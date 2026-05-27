<?php

namespace App\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contract for a single transformation step handler.
 *
 * Each implementation forwards a binary payload + scalar params to one HTTP
 * endpoint of the Python `embedder` service (Phase 2) and returns the bytes
 * produced by that step. The pipeline orchestrator ({@see \App\Service\AssetTransformation\PipelineRunner})
 * resolves handlers by their {@see self::supportedType()} and chains them.
 *
 * Implementations are auto-tagged `app.step_handler` and the runner consumes
 * them as an iterable.
 */
#[AutoconfigureTag('app.step_handler')]
interface StepHandlerInterface
{
    /**
     * Step type this handler is responsible for (one handler per StepType).
     */
    public static function supportedType(): StepType;

    /**
     * Per-step default timeout in milliseconds, bound from a Symfony parameter
     * (itself env-overridable, see D-08).
     */
    public function defaultTimeoutMs(): int;

    /**
     * Execute the step. Must not exceed `$timeoutMs` wall-clock.
     *
     * @param string $bytes     Binary input (output of the previous step or original asset)
     * @param array<string,mixed> $params  Step parameters (already validated upstream by StepParamsFactory, Plan 03-01)
     * @param int    $timeoutMs Effective per-step timeout = min(defaultTimeoutMs, remainingMs)
     *
     * @throws \App\Service\AssetTransformation\TransformationPipelineException on embedder 4xx/5xx after retries
     */
    public function run(string $bytes, array $params, int $timeoutMs): HandlerResult;
}
