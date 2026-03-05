<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Command;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlProfiler;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\PastPerfectBundle\Service\DetailParserService;
use Survos\PastPerfectBundle\Service\PastPerfectClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function dirname;
use function basename;
use function str_replace;
use function file_exists;
use function file_put_contents;
use function json_encode;

#[AsCommand('pastperfect:harvest-details', 'Fetch detail pages for every record in a listing JSONL and write a details JSONL')]
final class HarvestDetailCommand
{
    public function __construct(
        private readonly PastPerfectClient $client,
        private readonly DetailParserService $parser,
        private readonly JsonlStateRepository $stateRepository,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the listing JSONL produced by pastperfect:harvest-listing')]
        string $listingFile,
        #[Option('Output directory; defaults to the same directory as the listing file')]
        ?string $outputDir = null,
        #[Option('Force re-fetch even for records already in the output file')]
        bool $force = false,
        #[Option('Only profile the existing details JSONL; skip all HTTP fetching')]
        bool $profileOnly = false,
        #[Option('Maximum records to process (0 = unlimited)')]
        int $limit = 0,
    ): int {
        $outputDir   = $outputDir ?? dirname($listingFile);
        $detailsPath = $outputDir . '/' . str_replace('-listing.jsonl', '-details.jsonl', basename($listingFile));
        $profilePath = $detailsPath . '.profile.json';

        if ($profileOnly) {
            return $this->runProfile($io, $detailsPath, $profilePath);
        }

        if (!file_exists($listingFile)) {
            $io->error(sprintf('Listing file not found: %s', $listingFile));

            return Command::FAILURE;
        }

        $alreadyFetched = $this->loadFetchedIds($detailsPath);
        $io->text(sprintf('%d records already in output file.', count($alreadyFetched)));

        $mode   = ($force || count($alreadyFetched) === 0) ? 'w' : 'a';
        $writer = JsonlWriter::open($detailsPath, mode: $mode);

        $io->title(sprintf('Harvesting details → %s', $detailsPath));

        $count   = 0;
        $skipped = 0;
        $errors  = 0;

        foreach (JsonlReader::open($listingFile) as $record) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $id   = $record['id']   ?? null;
            $url  = $record['url']  ?? null;
            $site = $record['site'] ?? ($url !== null ? $this->client->siteSlug($url) : '');

            if ($id === null || $url === null) {
                continue;
            }

            $tokenCode = ($record['type'] ?? 'item') . ':' . $id;

            if (!$force && isset($alreadyFetched[$id])) {
                $skipped++;
                continue;
            }

            $wasCached = $this->client->isDetailCached($url, $site);

            try {
                $html   = $this->client->fetchDetail($url, $site);
                $detail = $this->parser->parse($html, $record);
                $writer->write($detail, $tokenCode);
                $count++;

                if ($count % 50 === 0) {
                    $io->text(sprintf('  %d fetched, %d skipped, %d errors…', $count, $skipped, $errors));
                }

                if (!$wasCached && $this->client->throttle() > 0) {
                    sleep((int) $this->client->throttle());
                }
            } catch (\Throwable $e) {
                $errors++;
                $io->warning(sprintf('  Failed %s: %s', $url, $e->getMessage()));
            }
        }

        $result = $writer->finish();

        $io->success(sprintf(
            'Done. %d fetched, %d skipped, %d errors. Total in file: %d. Output: %s',
            $count,
            $skipped,
            $errors,
            $result->state->getStats()->getRows(),
            $detailsPath,
        ));

        $this->runProfile($io, $detailsPath, $profilePath);

        return Command::SUCCESS;
    }

    private function runProfile(SymfonyStyle $io, string $detailsPath, string $profilePath): int
    {
        if (!file_exists($detailsPath)) {
            $io->warning(sprintf('Details file not found, cannot profile: %s', $detailsPath));

            return Command::FAILURE;
        }

        $io->section('Profiling details JSONL…');

        $profiler = new JsonlProfiler();
        $stats    = $profiler->profile(JsonlReader::open($detailsPath));

        $encoded = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed encoding profile to JSON.');
        }

        if (file_put_contents($profilePath, $encoded) === false) {
            throw new \RuntimeException(sprintf('Failed writing profile to "%s".', $profilePath));
        }

        $io->success(sprintf('Profile written: %s', $profilePath));
        $io->text(sprintf('Review with:  bin/console import:profile:report %s', $profilePath));

        return Command::SUCCESS;
    }

    /** @return array<string, true> */
    private function loadFetchedIds(string $detailsPath): array
    {
        if (!file_exists($detailsPath)) {
            return [];
        }

        $ids = [];
        foreach (JsonlReader::open($detailsPath) as $row) {
            if (isset($row['id'])) {
                $ids[$row['id']] = true;
            }
        }

        return $ids;
    }
}
