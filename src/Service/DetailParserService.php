<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Service;

use function trim;
use function strtolower;
use function preg_replace;
use function preg_match;
use function str_replace;

/**
 * Parses a PastPerfect Online detail page into a flat record array.
 *
 * Uses the PHP 8.4 Dom\HTMLDocument API — no external library required.
 *
 * ## Confirmed PPO DOM structure (observed on multiple sites)
 *
 * Catalog fields are rendered as a <table> of two-column rows:
 *
 *   <tr>
 *     <td class="category"><h3>Object Name</h3></td>
 *     <td class="display">Armband</td>
 *   </tr>
 *
 * The site copyright/attribution lives in the page footer:
 *
 *   <footer><p>© Fauquier Historical Society 2021</p></footer>
 *
 * There is NO dedicated rights/license field in the PPO catalog schema.
 * The only rights signal is the footer copyright notice. The `rights_notice`
 * field in the output record carries this text verbatim.
 */
final class DetailParserService
{
    /**
     * @param array<string, string> $listingRecord  The originating listing row
     * @return array<string, mixed>
     */
    public function parse(string $html, array $listingRecord): array
    {
        $dom = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $record = [
            'source' => $listingRecord['source'] ?? 'pastperfectonline',
            'site'   => $listingRecord['site']   ?? '',
            'type'   => $listingRecord['type']   ?? '',
            'id'     => $listingRecord['id']     ?? '',
            'url'    => $listingRecord['url']    ?? '',
        ];

        // --- Catalog fields: td.category h3 → td.display ---
        foreach ($this->extractCatalogFields($dom) as $label => $value) {
            $key = $this->normaliseKey($label);
            if ($key !== '' && !isset($record[$key])) {
                $record[$key] = $value;
            }
        }

        // --- Footer copyright / rights notice ---
        $rights = $this->extractRightsNotice($dom);
        if ($rights !== null) {
            $record['rights_notice'] = $rights;
        }

        // --- Media ---
        $media = $this->extractMedia($dom);
        if ($media !== []) {
            $record['media'] = $media;
        }

        return $record;
    }

    // ------------------------------------------------------------------
    // Catalog field extraction
    // ------------------------------------------------------------------

    /**
     * Extract label→value pairs from the confirmed PPO two-column table structure.
     *
     * Primary pattern:
     *   <td class="category"><h3>Label</h3></td>
     *   <td class="display">Value</td>
     *
     * Fallback patterns are attempted if the primary yields nothing (handles
     * theme variations or future PPO updates):
     *   - <dl><dt>Label</dt><dd>Value</dd></dl>
     *   - .field-label + .field-value sibling
     *   - [data-label] attribute
     *
     * @return array<string, string>
     */
    private function extractCatalogFields(\Dom\HTMLDocument $dom): array
    {
        $pairs = [];

        // Primary: confirmed PPO structure
        foreach ($dom->querySelectorAll('tr') as $tr) {
            $categoryCell = $tr->querySelector('td.category');
            $displayCell  = $tr->querySelector('td.display');

            if ($categoryCell === null || $displayCell === null) {
                continue;
            }

            // Label text is inside an <h3> inside the category cell
            $h3    = $categoryCell->querySelector('h3');
            $label = trim(($h3 ?? $categoryCell)->textContent);
            $value = trim($displayCell->textContent);

            if ($label !== '' && $value !== '') {
                $pairs[$label] = $value;
            }
        }

        if ($pairs !== []) {
            return $pairs;
        }

        // Fallback 1: definition lists
        foreach ($dom->querySelectorAll('dl') as $dl) {
            $pendingLabel = null;
            foreach ($dl->childNodes as $child) {
                if (!($child instanceof \Dom\Element)) {
                    continue;
                }
                $tag = strtolower($child->localName);
                if ($tag === 'dt') {
                    $pendingLabel = trim($child->textContent);
                } elseif ($tag === 'dd' && $pendingLabel !== null) {
                    $value = trim($child->textContent);
                    if ($pendingLabel !== '' && $value !== '') {
                        $pairs[$pendingLabel] = $value;
                    }
                    $pendingLabel = null;
                }
            }
        }

        // Fallback 2: .field-label / .field-value siblings
        foreach ($dom->querySelectorAll('.field-label') as $labelNode) {
            $label   = trim($labelNode->textContent);
            $sibling = $labelNode->nextElementSibling;
            if ($label !== '' && $sibling !== null && $sibling->classList->contains('field-value')) {
                $value = trim($sibling->textContent);
                if ($value !== '' && !isset($pairs[$label])) {
                    $pairs[$label] = $value;
                }
            }
        }

        // Fallback 3: [data-label] attributes
        foreach ($dom->querySelectorAll('[data-label]') as $node) {
            $label = trim($node->getAttribute('data-label'));
            $value = trim($node->textContent);
            if ($label !== '' && $value !== '' && !isset($pairs[$label])) {
                $pairs[$label] = $value;
            }
        }

        return $pairs;
    }

    // ------------------------------------------------------------------
    // Rights / copyright
    // ------------------------------------------------------------------

    /**
     * Extract the site copyright notice from the page footer.
     *
     * PastPerfect Online has no dedicated rights field in the catalog schema.
     * The only rights signal is the footer copyright, e.g.:
     *   "© Fauquier Historical Society 2021"
     *
     * This is a site-level attribution, not item-level. It is included in
     * every item record so consumers can make rights decisions downstream.
     */
    private function extractRightsNotice(\Dom\HTMLDocument $dom): ?string
    {
        $footer = $dom->querySelector('footer');
        if ($footer !== null) {
            $text = trim($footer->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Media
    // ------------------------------------------------------------------

    /**
     * @return array<array<string, string>>
     */
    private function extractMedia(\Dom\HTMLDocument $dom): array
    {
        $media = [];
        $seen  = [];

        foreach ($dom->querySelectorAll('img[src]') as $img) {
            $src = trim($img->getAttribute('src'));
            if ($src === '' || isset($seen[$src])) {
                continue;
            }
            if (preg_match('#/(icon|logo|spacer|blank|arrow|btn|button)#i', $src)) {
                continue;
            }

            $seen[$src] = true;
            $fullSrc    = str_replace('/thumb/', '/images/', $src);

            if ($fullSrc !== $src) {
                $media[] = ['type' => 'image', 'thumb_url' => $src, 'full_url' => $fullSrc];
            } else {
                $media[] = ['type' => 'image', 'url' => $src];
            }
        }

        return $media;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * "Object Name" → "object_name", "Date (circa)" → "date_circa"
     */
    private function normaliseKey(string $label): string
    {
        $key = strtolower($label);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';

        return trim($key, '_');
    }
}
