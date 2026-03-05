<?php

declare(strict_types=1);

namespace Survos\PastPerfectBundle\Command;

use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\Service\JsonlStateRepository;
use Survos\SiteDiscoveryBundle\Service\CdxDiscoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

#[AsCommand('pastperfect:discover-registry', 'Discover PastPerfect Online sites via the Internet Archive CDX API and write a registry listing JSONL')]
final class DiscoverRegistryCommand
{
    public const DEFAULT_OUTPUT = 'data/pastperfect/registry-listing.jsonl';

    private const PPO_DOMAIN      = 'pastperfectonline.com';
    private const PPO_SURT_PREFIX = 'com,pastperfectonline,';

    public function __construct(
        private readonly CdxDiscoveryService $cdx,
        private readonly JsonlStateRepository $stateRepository,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Output path for the registry listing JSONL')]
        string $output = self::DEFAULT_OUTPUT,
        #[Option('Force re-discovery even if a completed listing already exists')]
        bool $force = false,
        #[Option('Stop after this many unique sites (0 = unlimited); useful for testing')]
        int $limit = 0,
    ): int {
        if (!$force) {
            $state = $this->stateRepository->load($output);
            if ($state->exists() && $state->isFresh() && $state->getStats()->isCompleted()) {
                $io->success(sprintf(
                    'Registry listing already complete (%d sites). Use --force to re-discover. File: %s',
                    $state->getStats()->getRows(),
                    $output,
                ));

                return Command::SUCCESS;
            }
        }

        $mode   = $force ? 'w' : 'a';
        $writer = JsonlWriter::open($output, mode: $mode);

        $io->title('Discovering PastPerfect Online sites via Internet Archive CDX');
        $io->text(sprintf('Writing to: %s (mode=%s, limit=%s)', $output, $mode, $limit ?: 'unlimited'));

        $count = 0;
        try {
            foreach ($this->cdx->discover(self::PPO_DOMAIN, self::PPO_SURT_PREFIX, limit: $limit) as $site) {
                $writer->write($site->toArray(), tokenCode: $site->host);
                $count++;

                if ($count % 100 === 0) {
                    $io->text(sprintf('  %d sites discovered…', $count));
                }
            }
        } catch (\Throwable $e) {
            $writer->close();
            $io->error(sprintf('Discovery failed after %d sites: %s', $count, $e->getMessage()));

            return Command::FAILURE;
        }

        $result = $writer->finish();

        $io->success(sprintf(
            '%d unique sites written to %s',
            $result->state->getStats()->getRows(),
            $output,
        ));
        $io->text(sprintf('Next step: bin/console pastperfect:probe-registry %s', $output));

        return Command::SUCCESS;
    }
}
