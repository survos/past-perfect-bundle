<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Service;

use Symfony\Component\DomCrawler\Crawler;
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

/**
 * Thin HTTP wrapper around a PastPerfect Online site.
 *
 * Responsibilities:
 *  - Build the search/listing URL
 *  - Fetch pages with throttle
 *  - Parse record links from listing HTML
 *  - Detect "next page" links and follow pagination
 *
 * Does NOT write any files – callers own that responsibility.
 */
final class PastPerfectClient
{
    /**
     * Param names used by the AdvancedSearch listing endpoint.
     * All category slots are left empty to match everything.
     */
    private const SEARCH_PARAMS = [
        'advanceSearchActivated' => 'False',
        'firstTimeSearch'        => 'False',
        'search_include_objects'    => 'true',
        'search_include_photos'     => 'true',
        'search_include_archives'   => 'true',
        'search_include_library'    => 'true',
        'search_include_creators'   => 'true',
        'search_include_people'     => 'true',
        'search_include_containers' => 'true',
        'actionType'             => 'Search',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly float $throttle = 1.0,
        private readonly string $userAgent = 'SurvosPastPerfectBundle Harvester',
    ) {}

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
        $parts = explode('.', $host);

        return $parts[0];
    }

    /**
     * Iterate over every listing record on the site, yielding one array per record.
     *
     * Each yielded value:
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

        $nextUrl = $baseUrl . '/AdvancedSearch?' . http_build_query(self::SEARCH_PARAMS);

        while ($nextUrl !== null) {
            $html    = $this->fetch($nextUrl);
            $crawler = new Crawler($html, $baseUrl);

            yield from $this->parseRecords($crawler, $baseUrl, $site);

            $nextUrl = $this->findNextPageUrl($crawler, $baseUrl);

            if ($nextUrl !== null && $this->throttle > 0) {
                sleep((int) $this->throttle);
            }
        }
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function fetch(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept'     => 'text/html,application/xhtml+xml',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf(
                'HTTP %d fetching "%s".',
                $statusCode,
                $url,
            ));
        }

        return $response->getContent();
    }

    /**
     * Parse all record links from a listing page.
     *
     * PastPerfect listing pages contain anchor tags whose href matches:
     *   /{type}/{UUID}
     *
     * Examples:
     *   /webobject/AC429E12-B023-4E3D-BEC0-693892645021
     *   /photo/DEADBEEF-...
     *
     * @return array<array<string, string>>
     */
    private function parseRecords(Crawler $crawler, string $baseUrl, string $site): array
    {
        $records = [];
        $seen    = [];

        $crawler->filter('a[href]')->each(function (Crawler $node) use ($baseUrl, $site, &$records, &$seen): void {
            $href = trim($node->attr('href') ?? '');
            if ($href === '') {
                return;
            }

            // Normalise to absolute
            if (!str_contains($href, '://')) {
                $href = $baseUrl . '/' . ltrim($href, '/');
            }

            // Match /{type}/{UUID}
            if (!preg_match('#/([a-z]+)/([0-9A-Fa-f\-]{36})$#', $href, $m)) {
                return;
            }

            $type = $m[1];
            $id   = $m[2];
            $key  = $type . ':' . $id;

            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;

            $records[] = [
                'source' => 'pastperfectonline',
                'site'   => $site,
                'type'   => $type,
                'id'     => $id,
                'url'    => $baseUrl . '/' . $type . '/' . $id,
            ];
        });

        return $records;
    }

    /**
     * Find the "next page" URL in the listing pagination, or null if on the last page.
     */
    private function findNextPageUrl(Crawler $crawler, string $baseUrl): ?string
    {
        // PastPerfect Online uses a link with rel="next" or a "Next" text anchor.
        // Try rel="next" first.
        $nextLink = $crawler->filter('a[rel="next"]');
        if ($nextLink->count() > 0) {
            return $this->absolutise($nextLink->first()->attr('href') ?? '', $baseUrl);
        }

        // Fall back: anchor whose text is "Next" or ">" inside pagination controls.
        $found = null;
        $crawler->filter('a')->each(function (Crawler $node) use ($baseUrl, &$found): void {
            if ($found !== null) {
                return;
            }
            $text = trim($node->text());
            if ($text === 'Next' || $text === '>') {
                $href = $node->attr('href') ?? '';
                if ($href !== '' && $href !== '#') {
                    $found = $this->absolutise($href, $baseUrl);
                }
            }
        });

        return $found;
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
