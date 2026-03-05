<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Command;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\PastPerfectBundle\Message\ProbeRegistrySiteMessage;
use Survos\PastPerfectBundle\Command\DiscoverRegistryCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

use function sprintf;
use function dirname;
use function str_replace;
use function basename;
use function file_exists;

#[AsCommand('pastperfect:probe-registry', 'Validate each site in a registry listing JSONL by dispatching probe messages')]
final class ProbeRegistryCommand
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly JsonlStateRepository $stateRepository,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the registry listing JSONL (from pastperfect:discover-registry)')]
        string $listingFile = DiscoverRegistryCommand::DEFAULT_OUTPUT,
        #[Option('Output path for the probed registry JSONL')]
        ?string $output = null,
        #[Option('Force re-probe all sites, ignoring already-probed entries')]
        bool $force = false,
        #[Option('Maximum sites to probe in this run (0 = unlimited)')]
        int $limit = 0,
    ): int {
        if (!file_exists($listingFile)) {
            $io->error(sprintf('Listing file not found: %s', $listingFile));

            return Command::FAILURE;
        }

        $output = $output ?? dirname($listingFile) . '/'
            . str_replace('-listing.jsonl', '-probed.jsonl', basename($listingFile));

        // Build set of already-probed hosts so we can skip them on resume
        $alreadyProbed = $this->loadProbedHosts($output);
        $io->text(sprintf('%d sites already probed.', count($alreadyProbed)));

        $io->title(sprintf('Dispatching probe messages → %s', $output));
        $io->text('Route Survos\\PastPerfectBundle\\Message\\ProbeRegistrySiteMessage to an async');
        $io->text('transport to process in the background, or leave unrouted for synchronous handling.');

        $dispatched = 0;
        $skipped    = 0;

        foreach (JsonlReader::open($listingFile) as $record) {
            if ($limit > 0 && $dispatched >= $limit) {
                break;
            }

            $host    = $record['host']    ?? null;
            $baseUrl = $record['base_url'] ?? null;

            if ($host === null || $baseUrl === null) {
                continue;
            }

            if (!$force && isset($alreadyProbed[$host])) {
                $skipped++;
                continue;
            }

            $this->bus->dispatch(new ProbeRegistrySiteMessage(
                host:          $host,
                baseUrl:       $baseUrl,
                discoveredVia: $record['discovered_via'] ?? 'internet_archive_cdx',
                registryPath:  $output,
            ));

            $dispatched++;

            if ($dispatched % 100 === 0) {
                $io->text(sprintf('  %d dispatched, %d skipped…', $dispatched, $skipped));
            }
        }

        $io->success(sprintf(
            '%d probe messages dispatched, %d skipped. Results → %s',
            $dispatched,
            $skipped,
            $output,
        ));

        return Command::SUCCESS;
    }

    /** @return array<string, true> */
    private function loadProbedHosts(string $probedPath): array
    {
        if (!file_exists($probedPath)) {
            return [];
        }

        $hosts = [];
        foreach (JsonlReader::open($probedPath) as $row) {
            if (isset($row['host'])) {
                $hosts[$row['host']] = true;
            }
        }

        return $hosts;
    }
}
