<?php

namespace App\Service\AssetTransformation;

/**
 * Final value returned by {@see PipelineRunner::run()}.
 *
 * Carried as a single immutable struct so the public route controller
 * (Plan 03-03) can both stream the bytes AND surface debug metadata
 * (warnings, applied steps, total duration) as response headers.
 */
final readonly class PipelineResult
{
    /**
     * @param array<int, array{code: string, stepIndex: int|null}> $warnings
     * @param list<string>                                          $appliedSteps
     */
    public function __construct(
        public string $bytes,
        public string $contentType,
        public int $totalMs,
        public array $warnings,
        public array $appliedSteps,
    ) {
    }
}
