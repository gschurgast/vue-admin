<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Message\WarmupTransformationVariantMessage;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plan 05-03 — OPS-01 — `php bin/console transformations:warm`.
 *
 * Dispatch a {@see WarmupTransformationVariantMessage} on the `transformations`
 * transport (configured in Plan 05-02). Manual operation only — there is no
 * automatic warmup at deploy time (OPS-06).
 */
#[AsCommand(
    name: 'transformations:warm',
    description: 'Dispatch a warmup job for one transformation+asset pair (manual op, no auto-backfill at deploy).',
)]
final class TransformationsWarmCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Transformation code (kebab-case).')
            ->addOption('asset-id', null, InputOption::VALUE_REQUIRED,
                'Required: target asset id. Bulk mode (omit --asset-id) is NOT supported in v1.0.')
            ->addOption('ext', null, InputOption::VALUE_REQUIRED,
                'Output extension to warm (png|jpg|webp|...). Defaults to png.', 'png');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $code = (string) $input->getArgument('code');
        $assetIdRaw = $input->getOption('asset-id');
        $ext = ltrim((string) $input->getOption('ext'), '.') ?: 'png';

        // T-05-14 — bulk mode explicitly out-of-scope.
        if ($assetIdRaw === null || $assetIdRaw === '' || !ctype_digit((string) $assetIdRaw)) {
            $io->error('--asset-id is required and must be a positive integer (bulk mode is not supported in v1.0).');
            return Command::FAILURE;
        }
        $assetId = (int) $assetIdRaw;

        $tx = $this->doctrine->getRepository(AssetTransformation::class)->findOneBy(['code' => $code]);
        if (!$tx instanceof AssetTransformation) {
            $io->error(sprintf('Transformation not found: %s', $code));
            return Command::FAILURE;
        }

        $asset = $this->doctrine->getRepository(Asset::class)->find($assetId);
        if (!$asset instanceof Asset) {
            $io->error(sprintf('Asset not found: id=%d', $assetId));
            return Command::FAILURE;
        }

        // T-05-15 — warmup target must be public (aligned with /t/* T-03-XX + preview T-05-03).
        if (!$asset->isPublic()) {
            $io->error(sprintf('Asset must be public to be warmed (id=%d, isPublic=false).', $assetId));
            return Command::FAILURE;
        }

        $this->bus->dispatch(new WarmupTransformationVariantMessage(
            (int) $tx->getId(),
            (int) $asset->getId(),
            $ext,
        ));

        $io->success(sprintf(
            'Warmup dispatched: transformation=%s (id=%d), asset=%d, ext=%s',
            $tx->getCode(),
            $tx->getId(),
            $asset->getId(),
            $ext,
        ));

        return Command::SUCCESS;
    }
}
