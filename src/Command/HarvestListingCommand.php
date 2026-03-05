<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Command;

use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\PastPerfectBundle\Service\PastPerfectClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function rtrim;
use function sprintf;
use function dirname;

#[AsCommand('pastperfect:harvest-listing', 'Harvest the listing index from a PastPerfect Online site into a JSONL file')]
final class HarvestListingCommand
{
    public function __construct(
        private readonly PastPerfectClient $client,
        private readonly JsonlStateRepository $stateRepository,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Base URL of the PastPerfect Online site (e.g. https://fauquierhistory.pastperfectonline.com)')]
        string $baseUrl,
        #[Option('Output directory for the JSONL file')]
        string $outputDir = 'data/pastperfect',
        #[Option('Force re-harvest even if a completed listing file already exists')]
        bool $force = false,
    ): int {
        $baseUrl = rtrim($baseUrl, '/');
        $site    = $this->client->siteSlug($baseUrl);

        $jsonlPath = sprintf('%s/%s/%s-listing.jsonl', $outputDir, $site, $site);

        // Resume guard: skip if sidecar says completed and file is fresh.
        if (!$force) {
            $state = $this->stateRepository->load($jsonlPath);
            if ($state->exists() && $state->isFresh() && $state->getStats()->isCompleted()) {
                $io->success(sprintf(
                    'Listing already complete (%d records). Use --force to re-harvest. File: %s',
                    $state->getStats()->getRows(),
                    $jsonlPath,
                ));

                return Command::SUCCESS;
            }
        }

        $mode   = $force ? 'w' : 'a';
        $writer = JsonlWriter::open($jsonlPath, mode: $mode);

        $io->title(sprintf('Harvesting listing from %s', $baseUrl));
        $io->text(sprintf('Writing to: %s (mode=%s)', $jsonlPath, $mode));

        $count = 0;
        try {
            foreach ($this->client->iterateListing($baseUrl) as $record) {
                $tokenCode = $record['type'] . ':' . $record['id'];
                $writer->write($record, $tokenCode);
                $count++;

                if ($count % 100 === 0) {
                    $io->text(sprintf('  %d records harvested…', $count));
                }
            }
        } catch (\Throwable $e) {
            // Close writer so sidecar is persisted before re-throwing.
            $writer->close();
            $io->error(sprintf('Harvest failed after %d records: %s', $count, $e->getMessage()));

            return Command::FAILURE;
        }

        $result = $writer->finish();

        $io->success(sprintf(
            'Done. %d records written to %s',
            $result->state->getStats()->getRows(),
            $jsonlPath,
        ));

        return Command::SUCCESS;
    }
}
