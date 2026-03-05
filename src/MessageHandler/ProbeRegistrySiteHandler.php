<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\MessageHandler;

use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\PastPerfectBundle\Message\ProbeRegistrySiteMessage;
use Survos\PastPerfectBundle\Service\SiteProbeService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

/**
 * Validates that a candidate host is a live PastPerfect Online site
 * and appends the result to the registry JSONL.
 *
 * Runs synchronously when no transport is configured for
 * ProbeRegistrySiteMessage, or asynchronously when the application
 * routes the message to a transport (Doctrine, AMQP, Redis, etc.).
 */
#[AsMessageHandler]
final class ProbeRegistrySiteHandler
{
    public function __construct(
        private readonly SiteProbeService $probeService,
    ) {}

    public function __invoke(ProbeRegistrySiteMessage $message): void
    {
        $result = $this->probeService->probe($message->host, $message->baseUrl);

        $record = [
            'host'           => $message->host,
            'base_url'       => $message->baseUrl,
            'discovered_via' => [$message->discoveredVia],
            'validated'      => $result->isLive,
            'validated_at'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'record_count'   => $result->recordCount,
            'title'          => $result->title,
            'error'          => $result->error,
        ];

        // Token = host; writer skips duplicate hosts if already written.
        $writer = JsonlWriter::open($message->registryPath, mode: 'a');
        $writer->write($record, tokenCode: $message->host);
        $writer->close();
    }
}
