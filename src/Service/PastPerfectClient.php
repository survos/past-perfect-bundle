<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

use function sprintf;
use function sleep;
use function parse_url;
use function explode;
use function ltrim;
use function str_contains;
use function rtrim;
use function trim;
use function preg_match;
use function sha1;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_dir;
use function mkdir;
use function dirname;
use function http_build_query;

/**
 * Thin HTTP wrapper around a PastPerfect Online site.
 *
 * Uses the PHP 8.4 Dom\HTMLDocument API for spec-compliant HTML5 parsing
 * with querySelector/querySelectorAll — no external DOM library required.
 *
 * Responsibilities:
 *  - Build the search/listing URL
 *  - Fetch pages with throttle, caching raw HTML to disk
 *  - Parse record links from listing HTML
 *  - Detect "next page" links and follow pagination
 *  - Fetch individual detail pages (also cached)
 *
 * Does NOT write JSONL or parse detail fields — callers own that.
 */
final class PastPerfectClient
{
    /**
     * Param names used by the AdvancedSearch listing endpoint.
     * All category slots are left empty to match everything.
     * resultsPerPage=100 is the maximum PPO/CatalogAccess will honour;
     * using it cuts the number of HTTP round-trips by ~5×.
     */
    private const SEARCH_PARAMS = [
        'advanceSearchActivated'    => 'False',
        'firstTimeSearch'           => 'False',
        'search_include_objects'    => 'true',
        'search_include_photos'     => 'true',
        'search_include_archives'   => 'true',
        'search_include_library'    => 'true',
        'search_include_creators'   => 'true',
        'search_include_people'     => 'true',
        'search_include_containers' => 'true',
        'actionType'                => 'Search',
        'resultsPerPage'            => '100',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly float $throttle = 1.0,
        private readonly string $userAgent = 'SurvosPastPerfectBundle Harvester',
        private readonly ?string $cacheDir = null,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Extract the subdomain/site slug from a base URL.
     *
     * Example: https://fauquierhistory.pastperfectonline.com/ → fauquierhistory
     */
    public function siteSlug(string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($host === false || $host === null) {
            throw new \InvalidArgumentException(sprintf('Cannot parse host from URL "%s".', $baseUrl));
        }

        return explode('.', $host)[0];
    }

    /**
     * Derive a source label from the domain of the base URL.
     *
     * Example: https://fauquierhistory.pastperfectonline.com/ → pastperfectonline
     *          https://foo.catalogaccess.com/                 → catalogaccess
     */
    public function sourceLabel(string $baseUrl): string
    {
        $host  = parse_url($baseUrl, PHP_URL_HOST) ?? '';
        $parts = explode('.', $host);
        // Second-to-last segment is the domain name (e.g. "pastperfectonline")
        return count($parts) >= 2 ? $parts[count($parts) - 2] : 'pastperfectonline';
    }

    /**
     * Iterate over every listing record on the site, yielding one array per record.
     *
     * Yielded shape:
     * [
     *   'source' => 'pastperfectonline',
     *   'site'   => 'fauquierhistory',
     *   'type'   => 'webobject',
     *   'id'     => 'AC429E12-B023-4E3D-BEC0-693892645021',
     *   'url'    => 'https://fauquierhistory.pastperfectonline.com/webobject/AC429E12-...',
     * ]
     *
     * @return \Generator<array<string, string>>
     */
    public function iterateListing(string $baseUrl): \Generator
    {
        $baseUrl = rtrim($baseUrl, '/');
        $site    = $this->siteSlug($baseUrl);
        $source  = $this->sourceLabel($baseUrl);
        $nextUrl = $baseUrl . '/AdvancedSearch?' . http_build_query(self::SEARCH_PARAMS);

        while ($nextUrl !== null) {
            $html = $this->fetchCached($nextUrl, $site, 'listing');
            $dom  = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

            yield from $this->parseRecords($dom, $baseUrl, $site, $source);

            $nextUrl = $this->findNextPageUrl($dom, $baseUrl);

            if ($nextUrl !== null && $this->throttle > 0) {
                sleep((int) $this->throttle);
            }
        }
    }

    /**
     * Build a concrete listing page URL.
     *
     * Page 1 omits the "page" query parameter because PPO uses the bare
     * AdvancedSearch URL for the first result page.
     */
    public function listingPageUrl(string $baseUrl, int $page = 1): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $params = self::SEARCH_PARAMS;

        if ($page > 1) {
            $params['page'] = (string) $page;
        }

        return $baseUrl . '/AdvancedSearch?' . http_build_query($params);
    }

    /**
     * Parse one fetched listing HTML page into flat listing rows.
     *
     * @return array<array<string, string>>
     */
    public function parseListingHtml(string $html, string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $site    = $this->siteSlug($baseUrl);
        $source  = $this->sourceLabel($baseUrl);
        $dom     = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return $this->parseRecords($dom, $baseUrl, $site, $source);
    }

    /**
     * Fetch and cache the raw HTML for a detail page.
     *
     * Returns the HTML string; callers parse what they need.
     * A second call for the same URL returns the cached copy without an HTTP request.
     */
    public function fetchDetail(string $url, string $site): string
    {
        return $this->fetchCached($url, $site, 'detail');
    }

    /**
     * Return the filesystem path where a detail page would be cached, or null if caching is off.
     */
    public function detailCachePath(string $url, string $site): ?string
    {
        if ($this->cacheDir === null) {
            return null;
        }

        return $this->buildCachePath($url, $site, 'detail');
    }

    /**
     * True if the detail page for this URL is already on disk.
     */
    public function isDetailCached(string $url, string $site): bool
    {
        $path = $this->detailCachePath($url, $site);

        return $path !== null && is_file($path);
    }

    public function throttle(): float
    {
        return $this->throttle;
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Fetch a URL, returning from disk cache when available.
     *
     * Cache layout: {cacheDir}/{site}/{type}/{sha1(url)}.html
     */
    private function fetchCached(string $url, string $site, string $type): string
    {
        if ($this->cacheDir !== null) {
            $cachePath = $this->buildCachePath($url, $site, $type);

            if (is_file($cachePath)) {
                $html = file_get_contents($cachePath);
                if ($html === false) {
                    throw new \RuntimeException(sprintf('Failed reading cache file "%s".', $cachePath));
                }

                return $html;
            }
        }

        $html = $this->fetchHttp($url);

        if ($this->cacheDir !== null) {
            /** @var string $cachePath — guaranteed set above when cacheDir !== null */
            $dir = dirname($cachePath);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Failed creating cache directory "%s".', $dir));
            }
            if (file_put_contents($cachePath, $html) === false) {
                throw new \RuntimeException(sprintf('Failed writing cache file "%s".', $cachePath));
            }
        }

        return $html;
    }

    private function buildCachePath(string $url, string $site, string $type): string
    {
        return sprintf('%s/%s/%s/%s.html', rtrim($this->cacheDir ?? '', '/'), $site, $type, sha1($url));
    }

    private function fetchHttp(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept'     => 'text/html,application/xhtml+xml',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('HTTP %d fetching "%s".', $statusCode, $url));
        }

        return $response->getContent();
    }

    /**
     * Parse all record links from a listing page using PHP 8.4 Dom\HTMLDocument.
     *
     * PastPerfect Online record links match: /{type}/{UUID}
     *   e.g. /webobject/AC429E12-B023-4E3D-BEC0-693892645021
     *
     * @return array<array<string, string>>
     */
    private function parseRecords(\Dom\HTMLDocument $dom, string $baseUrl, string $site, string $source): array
    {
        $records = [];
        $seen    = [];

        foreach ($dom->querySelectorAll('a[href]') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            if (!str_contains($href, '://')) {
                $href = $baseUrl . '/' . ltrim($href, '/');
            }

            // Match /{type}/{UUID-v4}
            if (!preg_match('#/([a-z]+)/([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})$#', $href, $m)) {
                continue;
            }

            $type = $m[1];
            $id   = $m[2];
            $key  = $type . ':' . $id;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $records[] = [
                'source' => $source,
                'site'   => $site,
                'type'   => $type,
                'id'     => $id,
                'url'    => $baseUrl . '/' . $type . '/' . $id,
            ];
        }

        return $records;
    }

    /**
     * Find the "next page" URL, or null when on the last page.
     */
    private function findNextPageUrl(\Dom\HTMLDocument $dom, string $baseUrl): ?string
    {
        // Prefer explicit rel="next"
        $nextLink = $dom->querySelector('a[rel="next"]');
        if ($nextLink !== null) {
            $href = $nextLink->getAttribute('href');
            if ($href !== null && $href !== '' && $href !== '#') {
                return $this->absolutise($href, $baseUrl);
            }
        }

        // Fall back: anchor whose visible text is "Next" or ">"
        foreach ($dom->querySelectorAll('a') as $anchor) {
            $text = trim($anchor->textContent);
            if ($text === 'Next' || $text === '>') {
                $href = $anchor->getAttribute('href');
                if ($href !== null && $href !== '' && $href !== '#') {
                    return $this->absolutise($href, $baseUrl);
                }
            }
        }

        return null;
    }

    private function absolutise(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || $href === '#') {
            return null;
        }
        if (str_contains($href, '://')) {
            return $href;
        }

        return $baseUrl . '/' . ltrim($href, '/');
    }
}
