<?php

namespace App\MessageHandler;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Message\WarmupTransformationVariantMessage;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\TransformationPipelineException;
use App\Service\AssetTransformation\TransformationStorageKey;
use App\Service\AssetTransformation\VariantCache;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Plan 05-02 — OPS-03 / OPS-04 — Warmup variant handler.
 *
 * Consumes {@see WarmupTransformationVariantMessage} from the `transformations`
 * Messenger transport (Redis Streams). For each (transformationId, assetId, ext):
 *
 *   1. Load entities (idempotent: missing tx OR missing asset → warn + return).
 *   2. Short-circuit if the variant is already in the cache (warmup is
 *      idempotent — replays should not re-render).
 *   3. Read original asset bytes via Flysystem (`assets.storage`).
 *   4. Pipe through {@see PipelineRunner} (same path as the public route).
 *   5. Write the rendered variant to the Flysystem cache via
 *      {@see VariantCache::write()} — warmup PRE-FILLS the cache (A6 RESEARCH).
 *
 * Exceptions from PipelineRunner are re-thrown so Messenger applies its retry
 * strategy (3× exponential, see config/packages/messenger.yaml). After max
 * retries the message lands in `transformations_failed` (OPS-04).
 */
#[AsMessageHandler]
final class WarmupTransformationVariantHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PipelineRunner $runner,
        private readonly VariantCache $cache,
        #[Autowire(service: 'assets.storage')]
        private readonly FilesystemOperator $assetsStorage,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\App\Service\TransformationMetrics $metrics = null,
    ) {
    }

    public function __invoke(WarmupTransformationVariantMessage $message): void
    {
        try {
            $this->process($message);
            $this->metrics?->recordMessageHandled('transformations', 'success');
        } catch (\Throwable $e) {
            $this->metrics?->recordMessageHandled('transformations', 'failure');
            throw $e;
        }
    }

    private function process(WarmupTransformationVariantMessage $message): void
    {
        $tx = $this->em->getRepository(AssetTransformation::class)->find($message->transformationId);
        if (!$tx instanceof AssetTransformation) {
            $this->logger->warning('warmup: transformation not found', [
                'transformationId' => $message->transformationId,
            ]);
            return;
        }

        $asset = $this->em->getRepository(Asset::class)->find($message->assetId);
        if (!$asset instanceof Asset) {
            $this->logger->warning('warmup: asset not found', [
                'assetId' => $message->assetId,
            ]);
            return;
        }

        $versionHash = (string) $tx->getVersionHash();
        if ($versionHash === '') {
            $this->logger->warning('warmup: transformation has no versionHash yet', [
                'transformationId' => $tx->getId(),
            ]);
            return;
        }

        $ext = strtolower(ltrim($message->ext, '.'));
        $storageKey = TransformationStorageKey::forVariant(
            (int) $tx->getId(),
            $versionHash,
            (int) $asset->getId(),
            $ext,
        );

        // Idempotency: a warmup retry (or two warm commands launched back-to-back)
        // must NOT re-render a variant that is already on S3.
        if ($this->cache->has($storageKey)) {
            $this->logger->info('warmup: cache hit (no-op)', [
                'storageKey' => $storageKey,
                'transformationId' => $tx->getId(),
                'assetId' => $asset->getId(),
            ]);
            return;
        }

        $s3Key = (string) $asset->getS3Key();
        if ($s3Key === '') {
            $this->logger->warning('warmup: asset has no s3Key', ['assetId' => $asset->getId()]);
            return;
        }

        try {
            $bytes = $this->assetsStorage->read($s3Key);
        } catch (FilesystemException $e) {
            $this->logger->warning('warmup: unable to read asset binary', [
                'assetId' => $asset->getId(),
                's3Key' => $s3Key,
                'error' => $e->getMessage(),
            ]);
            // No re-throw: a missing/corrupt original is not transient — retrying
            // would just burn the failed queue.
            return;
        }

        try {
            $result = $this->runner->run($tx, $bytes, $ext);
        } catch (TransformationPipelineException $e) {
            $this->logger->warning('warmup: pipeline failed — message will be retried', [
                'transformationCode' => $tx->getCode(),
                'assetId' => $asset->getId(),
                'ext' => $ext,
                'pipelineCode' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
            // Re-throw so Messenger applies its retry strategy. After max
            // retries the message is auto-routed to `transformations_failed`.
            throw $e;
        }

        $this->cache->write($storageKey, $result->bytes, $result->contentType);

        $this->logger->info('warmup: variant cached', [
            'storageKey' => $storageKey,
            'transformationId' => $tx->getId(),
            'assetId' => $asset->getId(),
            'ext' => $ext,
            'durationMs' => $result->totalMs,
        ]);
    }
}
