<?php

namespace App\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Plan 04 — BGREMOVE-06. Calls POST /img/remove-background on the
 * embedder service (Phase 4 Python endpoint, D-17/D-18).
 *
 * Timeout: 6000 ms = 5 s Python hard cap (D-05) + 1 s network margin.
 * Retry: inherits embedder.client policy — 5xx + transport only; 4xx never
 * retried (D-07). The Python-side fallback already covers the 504 path when
 * `fallbackOnTimeout=true`, so a 504 here means "no fallback requested" and
 * surfaces as a TransformationPipelineException.
 */
final class RemoveBackgroundHandler extends AbstractEmbedderStepHandler
{
    public function __construct(
        #[Autowire(service: 'embedder.client')] HttpClientInterface $embedderClient,
        #[Autowire(param: 'transformations.embedder_timeout_remove_background_ms')] int $defaultTimeoutMs,
    ) {
        parent::__construct($embedderClient, $defaultTimeoutMs);
    }

    public static function supportedType(): StepType
    {
        return StepType::REMOVE_BACKGROUND;
    }

    protected function endpointPath(): string
    {
        return '/img/remove-background';
    }
}
