<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Message;

/**
 * Dispatched once per item record during a detail harvest.
 *
 * The handler fetches (or loads from cache) the detail page HTML,
 * parses it, and appends the result to the details JSONL.
 *
 * Applications can route this to any Messenger transport:
 *
 *   framework:
 *     messenger:
 *       routing:
 *         Survos\PastPerfectBundle\Message\ProbeItemMessage: async
 */
final readonly class ProbeItemMessage
{
    public function __construct(
        /** PastPerfect site slug, e.g. "fauquierhistory" */
        public string $site,

        /** Record type, e.g. "webobject", "photo", "archive" */
        public string $type,

        /** PastPerfect record UUID */
        public string $id,

        /** Absolute detail page URL */
        public string $url,

        /** Absolute path to the details JSONL file to append into */
        public string $detailsPath,
    ) {}
}
