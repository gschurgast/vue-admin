<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use App\Enum\StepType;

/**
 * Thrown by StepParamsFactory when a StepType is not yet supported by the
 * current phase (e.g. REMOVE_BACKGROUND in Phase 3, before Phase 4 wires
 * BiRefNet). Keeps the match() exhaustive without permissive fallback.
 */
final class UnsupportedStepTypeException extends \RuntimeException
{
    public function __construct(StepType $type, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Step type "%s" is not supported in the current phase.', $type->value),
            0,
            $previous,
        );
    }
}
