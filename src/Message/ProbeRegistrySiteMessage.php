<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Message;

/**
 * Dispatched once per candidate site in the registry.
 *
 * The handler validates the host is a live PPO site and writes
 * the result back into the registry JSONL.
 *
 * Applications can route this to any Messenger transport:
 *
 *   framework:
 *     messenger:
 *       routing:
 *         Survos\PastPerfectBundle\Message\ProbeRegistrySiteMessage: async
 */
final readonly class ProbeRegistrySiteMessage
{
    public function __construct(
        /** Fully-qualified host, e.g. "fauquierhistory.pastperfectonline.com" */
        public string $host,

        /** Absolute base URL, e.g. "https://fauquierhistory.pastperfectonline.com" */
        public string $baseUrl,

        /** Where the candidate was found: "internet_archive_cdx", "common_crawl", "manual" */
        public string $discoveredVia,

        /** Absolute path to the registry JSONL file to update on completion */
        public string $registryPath,
    ) {}
}
