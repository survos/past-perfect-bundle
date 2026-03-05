<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\MessageHandler;

use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\PastPerfectBundle\Message\ProbeItemMessage;
use Survos\PastPerfectBundle\Service\DetailParserService;
use Survos\PastPerfectBundle\Service\PastPerfectClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fetches (or loads from HTML cache) a single PastPerfect detail page,
 * parses it, and appends the result to the details JSONL.
 *
 * Runs synchronously when no transport is configured for ProbeItemMessage,
 * or asynchronously when the application routes it to a transport.
 */
#[AsMessageHandler]
final class ProbeItemHandler
{
    public function __construct(
        private readonly PastPerfectClient $client,
        private readonly DetailParserService $parser,
    ) {}

    public function __invoke(ProbeItemMessage $message): void
    {
        $listingRecord = [
            'source' => 'pastperfectonline',
            'site'   => $message->site,
            'type'   => $message->type,
            'id'     => $message->id,
            'url'    => $message->url,
        ];

        $html   = $this->client->fetchDetail($message->url, $message->site);
        $detail = $this->parser->parse($html, $listingRecord);

        $tokenCode = $message->type . ':' . $message->id;

        $writer = JsonlWriter::open($message->detailsPath, mode: 'a');
        $writer->write($detail, $tokenCode);
        $writer->close();
    }
}
