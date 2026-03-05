<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Service;

use Survos\PastPerfectBundle\Model\SiteProbeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function sprintf;
use function trim;
use function preg_match;

/**
 * Validates a candidate host by making a lightweight probe request to its
 * /AdvancedSearch endpoint and checking for PastPerfect Online page signatures.
 *
 * A single HEAD-then-GET strategy is used:
 *  1. GET /AdvancedSearch
 *  2. Verify HTTP 200
 *  3. Check for PPO-specific page markers
 *  4. Extract the approximate record count and page title if available
 */
final class SiteProbeService
{
    /**
     * Strings that appear in the HTML of a genuine PPO search results page.
     * We require at least one to be present.
     */
    private const PPO_SIGNATURES = [
        'pastperfectonline',
        'AdvancedSearch',
        'webobject',
    ];

    /**
     * Markers that indicate an inactive subdomain serving the PPO sample/demo
     * site rather than a real museum collection. Any match → reject.
     */
    private const SAMPLE_SITE_MARKERS = [
        'Sample site',                // literal text on the demo page
        '>PastPerfect Museum<',       // generic placeholder siteTitle
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent = 'SurvosPastPerfectBundle Harvester',
    ) {}

    public function probe(string $host, string $baseUrl): SiteProbeResult
    {
        $url = rtrim($baseUrl, '/') . '/AdvancedSearch?advanceSearchActivated=False&firstTimeSearch=False'
            . '&search_include_objects=true&search_include_photos=true'
            . '&search_include_archives=true&search_include_library=true'
            . '&actionType=Search';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                    'Accept'     => 'text/html,application/xhtml+xml',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return new SiteProbeResult(
                    isLive: false,
                    error: sprintf('HTTP %d', $response->getStatusCode()),
                );
            }

            $html = $response->getContent();
        } catch (\Throwable $e) {
            return new SiteProbeResult(isLive: false, error: $e->getMessage());
        }

        // Verify at least one PPO signature is present
        $matched = false;
        foreach (self::PPO_SIGNATURES as $sig) {
            if (str_contains($html, $sig)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return new SiteProbeResult(isLive: false, error: 'Page does not look like a PPO site');
        }

        // Reject inactive subdomains that serve the generic PPO sample/demo site
        foreach (self::SAMPLE_SITE_MARKERS as $marker) {
            if (str_contains($html, $marker)) {
                return new SiteProbeResult(isLive: false, error: 'Sample/demo site — subdomain not active');
            }
        }

        $dom   = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $title = $this->extractTitle($dom);
        $count = $this->extractRecordCount($dom, $html);

        return new SiteProbeResult(isLive: true, recordCount: $count, title: $title);
    }

    private function extractTitle(\Dom\HTMLDocument $dom): ?string
    {
        foreach (['h1', 'title', '.site-title', '.museum-name'] as $selector) {
            $node = $dom->querySelector($selector);
            if ($node !== null) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Try to extract the total record count from the search results page.
     *
     * PPO pages typically show something like "1–20 of 942 results".
     */
    private function extractRecordCount(\Dom\HTMLDocument $dom, string $html): ?int
    {
        // Try common result-count element selectors first
        foreach (['.result-count', '.total-results', '#totalResults', '.search-count'] as $selector) {
            $node = $dom->querySelector($selector);
            if ($node !== null) {
                $text = trim($node->textContent);
                if (preg_match('/(\d[\d,]*)/', $text, $m)) {
                    return (int) str_replace(',', '', $m[1]);
                }
            }
        }

        // Fall back to a regex on the raw HTML for "of NNN results" / "NNN records"
        if (preg_match('/\bof\s+([\d,]+)\s+(?:results?|records?)/i', $html, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }

        return null;
    }
}
