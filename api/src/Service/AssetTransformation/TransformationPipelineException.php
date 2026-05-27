<?php

namespace App\Service\AssetTransformation;

use RuntimeException;

/**
 * Domain exception raised by the transformation pipeline (Plan 03-02).
 *
 * The semantic `$code` lets the public route controller (Plan 03-03) map each
 * failure mode to the correct HTTP status without leaking internals:
 *
 * - CODE_CAP_EXCEEDED     → 503 + Retry-After
 * - CODE_EMBEDDER_ERROR   → 502
 * - CODE_UNSUPPORTED_STEP → 404 (D-05 sync-only AI gating)
 * - CODE_VALIDATION       → 422 (defensive; main validation lives in DTOs/Plan 03-01)
 */
final class TransformationPipelineException extends RuntimeException
{
    public const CODE_CAP_EXCEEDED = 1;
    public const CODE_EMBEDDER_ERROR = 2;
    public const CODE_UNSUPPORTED_STEP = 3;
    public const CODE_VALIDATION = 4;
}
