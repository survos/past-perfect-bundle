<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Model;

/**
 * Result of probing a single candidate PastPerfect Online host.
 */
final readonly class SiteProbeResult
{
    public function __construct(
        public bool $isLive,
        public ?int $recordCount = null,
        public ?string $title = null,
        public ?string $error = null,
    ) {}
}
