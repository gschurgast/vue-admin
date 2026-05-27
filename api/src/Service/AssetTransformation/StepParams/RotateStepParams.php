<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Params DTO for StepType::ROTATE.
 *
 * Phase 2 contract:
 *   { angle: int -360..360, background?: '#rrggbb' }
 */
final readonly class RotateStepParams
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Range(min: -360, max: 360)]
        public ?int $angle = null,
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')]
        public ?string $background = null,
    ) {
    }
}
