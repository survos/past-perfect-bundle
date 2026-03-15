<?php
declare(strict_types=1);

namespace Survos\PastPerfectBundle\Service;

use function trim;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strip_tags;
use function strtolower;
use function rtrim;

/**
 * Parses a PastPerfect Online homepage into institution metadata.
 *
 * Extracts from the standard PPO homepage layout:
 *   - Institution name (from copyright footer or <title>)
 *   - Address (street, city, state, zip)
 *   - Phone
 *   - Logo URL (museum branding image, not the background tile)
 *   - Copyright year
 *   - Welcome/about text (the introductory paragraph, if customised)
 *
 * Does NOT fetch anything — callers pass the HTML string.
 */
final class HomepageParserService
{
    /**
     * @return array{
     *   name: string|null,
     *   address: string|null,
     *   city: string|null,
     *   state: string|null,
     *   zip: string|null,
     *   phone: string|null,
     *   logo_url: string|null,
     *   copyright_year: int|null,
     *   about: string|null,
     * }
     */
    public function parse(string $html, string $baseUrl = ''): array
    {
        $dom = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return [
            'name'           => $this->extractName($dom, $html),
            'address'        => $this->extractAddress($html),
            'city'           => $this->extractCity($html),
            'state'          => $this->extractState($html),
            'zip'            => $this->extractZip($html),
            'phone'          => $this->extractPhone($html),
            'logo_url'       => $this->extractLogoUrl($dom, $html, $baseUrl),
            'copyright_year' => $this->extractCopyrightYear($html),
            'about'          => $this->extractAbout($dom),
        ];
    }

    // ── Name ────────────────────────────────────────────────────────────────

    private function extractName(\Dom\HTMLDocument $dom, string $html): ?string
    {
        // 1. Copyright line: "© 2022 The House of the Seven Gables"
        if (preg_match('/©\s*\d{4}\s+(.+?)\.?\s*$|©\s*\d{4}\s+(.+?)(?:\r|\n|<)/im', $html, $m)) {
            $name = trim($m[1] !== '' ? $m[1] : $m[2]);
            if ($name !== '' && strlen($name) < 120) {
                return $name;
            }
        }

        // 2. <title>: "Online Collections | Museum Name"
        $title = $dom->querySelector('title');
        if ($title !== null) {
            $text = trim($title->textContent);
            // Strip the generic prefix
            $text = preg_replace('/^Online Collections\s*[|\-–—]\s*/i', '', $text) ?? $text;
            if ($text !== '' && !str_starts_with(strtolower($text), 'online collections')) {
                return trim($text);
            }
        }

        // 3. Logo alt text
        foreach ($dom->querySelectorAll('img[alt]') as $img) {
            $alt = trim($img->getAttribute('alt'));
            if ($alt !== '' && strlen($alt) > 4 && !preg_match('/logo|banner|header|bg|background/i', $alt)) {
                return $alt;
            }
        }

        return null;
    }

    // ── Address ─────────────────────────────────────────────────────────────

    private function extractAddress(string $html): ?string
    {
        // Match street address: "115 Derby St, Salem, MA 01970"
        if (preg_match('/(\d+\s+[A-Za-z0-9 .]+(?:St|Ave|Rd|Blvd|Dr|Way|Lane|Ln|Pl|Court|Ct|Square|Sq|Pkwy|Hwy)[^<\r\n,]{0,40})/i', $html, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractCity(string $html): ?string
    {
        // "115 Derby St, Salem, MA 01970" — city is after the street
        if (preg_match('/\d+\s+[^,]+,\s*([A-Za-z][A-Za-z .]{2,40}),\s*[A-Z]{2}\s*\d{5}/i', $html, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractState(string $html): ?string
    {
        if (preg_match('/,\s*([A-Z]{2})\s*\d{5}/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractZip(string $html): ?string
    {
        if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractPhone(string $html): ?string
    {
        if (preg_match('/\((\d{3})\)\s*(\d{3})[-.\s](\d{4})/', $html, $m)) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }
        if (preg_match('/\b(\d{3})[-.\s](\d{3})[-.\s](\d{4})\b/', $html, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        return null;
    }

    // ── Logo ─────────────────────────────────────────────────────────────────

    private function extractLogoUrl(\Dom\HTMLDocument $dom, string $html, string $baseUrl): ?string
    {
        // PP stores logos at: museumlogos/logos/{id}/original/...png
        // Exclude background tile: museumlogos/images/
        if (preg_match('#((?:https?://[^"\']+)?museumlogos/logos/[^"\']+\.(?:png|jpg|gif|webp|svg))#i', $html, $m)) {
            $url = $m[1];
            // Relative → absolute
            if (!str_starts_with($url, 'http')) {
                $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
            }
            return $url;
        }

        // Fallback: first img in .header or .logo area
        foreach ($dom->querySelectorAll('.header img, .logo img, header img') as $img) {
            $src = trim($img->getAttribute('src'));
            if ($src !== '' && !preg_match('/background|tile|pattern/i', $src)) {
                if (!str_starts_with($src, 'http') && $baseUrl !== '') {
                    $src = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
                }
                return $src;
            }
        }

        return null;
    }

    // ── Copyright year ───────────────────────────────────────────────────────

    private function extractCopyrightYear(string $html): ?int
    {
        if (preg_match('/©\s*(\d{4})/', $html, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    // ── About / welcome text ─────────────────────────────────────────────────

    /**
     * Most PP sites show generic search-help text.  A minority customise the
     * homepage with a real "about" paragraph.  We detect the generic boilerplate
     * and skip it; anything else is a candidate about-text.
     */
    private function extractAbout(\Dom\HTMLDocument $dom): ?string
    {
        $boilerplate = [
            'keyword search',
            'advanced search',
            'random images',
            'tips for searching',
            'use cookies',
        ];

        // Look for a paragraph that doesn't smell like the PP boilerplate
        foreach ($dom->querySelectorAll('p, .welcome, .about, .intro, .description') as $node) {
            $text = trim($node->textContent);
            if (strlen($text) < 40) {
                continue;
            }
            $lower = strtolower($text);
            $isBoilerplate = false;
            foreach ($boilerplate as $phrase) {
                if (str_contains($lower, $phrase)) {
                    $isBoilerplate = true;
                    break;
                }
            }
            if (!$isBoilerplate) {
                return $text;
            }
        }

        return null;
    }
}
