<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AssetTransformation\AssetTransformation;
use Doctrine\Persistence\ManagerRegistry;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Plan 05-03 — OPS-02 — `php bin/console transformations:gc`.
 *
 * Garbage-collects S3/Flysystem variants whose hash is no longer in the
 * --keep most-recent set per transformation. The active versionHash is
 * ALWAYS preserved (force-included in the keep set), regardless of mtime.
 *
 * Default --keep=2 (rollback-friendly: keeps active + previous). Supersedes
 * the initial D-15 redaction (N=1); arbitré 2026-05-28 to align with the
 * ROADMAP.
 *
 * Non-interactive runs require --force (refuse silent destructive actions,
 * T-05-12). --dry-run lists totals without deleting.
 */
#[AsCommand(
    name: 'transformations:gc',
    description: 'GC orphan transformation variants (keep N most recent hashes; active hash always kept; default --keep=2 rollback-friendly).',
)]
final class TransformationsGcCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        #[Autowire(service: 'assets.storage')]
        private readonly FilesystemOperator $assetsStorage,
        #[Autowire(service: 'monolog.logger.transformations_metrics')]
        private readonly LoggerInterface $metricsLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'List orphan hashes and totals; do not delete anything.')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED,
                'Number of most-recent hashes to keep per transformation (active hash always included). Default 2 — rollback-friendly.', '2')
            ->addOption('force', null, InputOption::VALUE_NONE,
                'Skip interactive confirmation (required for non-interactive runs).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $keep = (int) $input->getOption('keep');
        if ($keep < 1) {
            $io->error('--keep must be >= 1');
            return Command::FAILURE;
        }

        $transformations = $this->doctrine->getRepository(AssetTransformation::class)->findAll();
        $grandBytes = 0;
        $grandTx = 0;
        $perTxDeletePlan = [];

        foreach ($transformations as $tx) {
            $activeFull = (string) $tx->getVersionHash();
            $activeHash = $activeFull !== '' ? substr($activeFull, 0, 8) : null;
            $prefix = sprintf('transformations/%d-v', $tx->getId());

            $hashes = [];
            foreach ($this->assetsStorage->listContents($prefix, deep: true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                if (!preg_match('#transformations/\d+-v([0-9a-f]+)/#', $item->path(), $m)) {
                    continue;
                }
                $h = $m[1];
                $hashes[$h] ??= ['bytes' => 0, 'count' => 0, 'mtime' => 0];
                $hashes[$h]['bytes'] += (int) ($item->fileSize() ?? 0);
                $hashes[$h]['count']++;
                $hashes[$h]['mtime'] = max($hashes[$h]['mtime'], (int) ($item->lastModified() ?? 0));
            }
            if ($hashes === []) {
                continue;
            }

            uasort($hashes, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
            $keepMap = [];
            if ($activeHash !== null && isset($hashes[$activeHash])) {
                $keepMap[$activeHash] = $hashes[$activeHash];
            }
            foreach ($hashes as $h => $info) {
                if (count($keepMap) >= $keep) {
                    break;
                }
                $keepMap[$h] ??= $info;
            }
            $toDelete = array_diff_key($hashes, $keepMap);
            if ($toDelete === []) {
                continue;
            }

            $perTxDeletePlan[] = ['tx' => $tx, 'active' => $activeHash, 'delete' => $toDelete];
            $bytes = array_sum(array_column($toDelete, 'bytes'));
            $grandBytes += $bytes;
            $grandTx++;

            $io->writeln(sprintf('Transformation: %s (id=%d, active=%s)', $tx->getCode(), $tx->getId(), $activeHash ?? 'none'));
            $io->writeln(sprintf('  To delete: %d hash(es)', count($toDelete)));
            foreach ($toDelete as $h => $info) {
                $io->writeln(sprintf('    - %s (%d variants, %s)', $h, $info['count'], self::humanBytes($info['bytes'])));
            }
            $io->writeln(sprintf('  Total to free: %s', self::humanBytes($bytes)));
            $io->writeln('---');
        }

        $io->writeln(sprintf('Grand total: %d transformations, %s to free', $grandTx, self::humanBytes($grandBytes)));

        if ($dryRun) {
            $io->note('Dry-run: no DELETE performed.');
            return Command::SUCCESS;
        }
        if ($perTxDeletePlan === []) {
            return Command::SUCCESS;
        }

        // T-05-12 — confirmation requise. Non-interactive: --force obligatoire.
        if (!$force && $input->isInteractive()) {
            if (!$io->confirm(sprintf('Proceed with DELETE of %s across %d transformations?', self::humanBytes($grandBytes), $grandTx), false)) {
                $io->warning('Aborted by user.');
                return Command::SUCCESS;
            }
        } elseif (!$force && !$input->isInteractive()) {
            $io->error('Non-interactive run requires --force (or use --dry-run). Refusing to delete.');
            return Command::FAILURE;
        }

        foreach ($perTxDeletePlan as $plan) {
            $tx = $plan['tx'];
            foreach ($plan['delete'] as $hash => $info) {
                $this->assetsStorage->deleteDirectory(sprintf('transformations/%d-v%s', $tx->getId(), $hash));
                $this->metricsLogger->info('transformations.gc.delete', [
                    'metric' => 'transformations.gc.delete',
                    'value' => 1,
                    'unit' => 'count',
                    'transformation_id' => $tx->getId(),
                    'transformation_code' => $tx->getCode(),
                    'hash' => $hash,
                    'bytes' => $info['bytes'],
                    'variants' => $info['count'],
                ]);
            }
        }
        $io->success(sprintf('GC complete: %s freed across %d transformations.', self::humanBytes($grandBytes), $grandTx));
        return Command::SUCCESS;
    }

    private static function humanBytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $b, $u[$i]);
    }
}