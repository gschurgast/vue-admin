<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 5 / Plan 05-05 — Façade métriques pour la pipeline AssetTransformation.
 *
 * Émet des logs JSON structurés sur le channel monolog `transformations_metrics`
 * (handler → php://stderr, JsonFormatter). La sortie est captée par Docker logging
 * et expédiée vers la stack obs cible (Datadog Logs / Loki / autre — branchement
 * Webfacto). Pas de dépendance directe à un client metrics (D-22).
 *
 * Restriction T-05-21 (anti-PII) : signatures strictement typées int/string/?int.
 * Aucune `array` libre, aucun payload utilisateur, pas de chemin S3, pas d'email.
 */
final class TransformationMetrics
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.transformations_metrics')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function recordCacheHit(int $txId, string $versionHash): void
    {
        $this->logger->info('transformations.cache.hit', [
            'metric' => 'transformations.cache.hit',
            'value' => 1,
            'unit' => 'count',
            'transformation_id' => $txId,
            'version_hash' => $versionHash,
        ]);
    }

    public function recordCacheMiss(int $txId, string $versionHash): void
    {
        $this->logger->info('transformations.cache.miss', [
            'metric' => 'transformations.cache.miss',
            'value' => 1,
            'unit' => 'count',
            'transformation_id' => $txId,
            'version_hash' => $versionHash,
        ]);
    }

    public function recordRenderDuration(int $txId, string $stepType, int $durationMs): void
    {
        $this->logger->info('transformations.render.duration_ms', [
            'metric' => 'transformations.render.duration_ms',
            'value' => $durationMs,
            'unit' => 'ms',
            'transformation_id' => $txId,
            'step_type' => $stepType,
        ]);
    }

    public function recordLockContention(int $txId, string $versionHash, int $waitMs): void
    {
        $this->logger->info('transformations.lock.contention_ms', [
            'metric' => 'transformations.lock.contention_ms',
            'value' => $waitMs,
            'unit' => 'ms',
            'transformation_id' => $txId,
            'version_hash' => $versionHash,
        ]);
    }

    public function recordEmbedderTimeout(string $stepType): void
    {
        $this->logger->info('transformations.embedder.timeout', [
            'metric' => 'transformations.embedder.timeout',
            'value' => 1,
            'unit' => 'count',
            'step_type' => $stepType,
        ]);
    }

    public function recordMessageHandled(string $transport, string $outcome): void
    {
        $this->logger->info('transformations.messenger.handled', [
            'metric' => 'transformations.messenger.handled',
            'value' => 1,
            'unit' => 'count',
            'transport' => $transport,
            'outcome' => $outcome,
        ]);
    }

    public function recordEmbedderHealth(int $inflight, ?int $lastInferenceMs): void
    {
        $this->logger->info('transformations.embedder.inflight', [
            'metric' => 'transformations.embedder.inflight',
            'value' => $inflight,
            'unit' => 'gauge',
        ]);

        if ($lastInferenceMs !== null) {
            $this->logger->info('transformations.embedder.last_inference_ms', [
                'metric' => 'transformations.embedder.last_inference_ms',
                'value' => $lastInferenceMs,
                'unit' => 'ms',
            ]);
        }
    }
}
