<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Params DTO for StepType::RESIZE.
 *
 * Mirrors the Python embedder /img/resize contract (Phase 2):
 *   { width?: int 1..8192, height?: int 1..8192, mode: 'fit'|'cover'|'contain', upscale?: bool }
 * with the constraint that at least one of (width, height) must be provided.
 */
final readonly class ResizeStepParams
{
    public function __construct(
        #[Assert\Range(min: 1, max: 8192)]
        public ?int $width = null,
        #[Assert\Range(min: 1, max: 8192)]
        public ?int $height = null,
        #[Assert\Choice(choices: ['fit', 'cover', 'contain'])]
        public string $mode = 'fit',
        public bool $upscale = false,
    ) {
    }

    #[Assert\Callback]
    public function validateAtLeastOneDim(ExecutionContextInterface $ctx): void
    {
        if ($this->width === null && $this->height === null) {
            $ctx->buildViolation('width or height required')
                ->atPath('width')
                ->addViolation();
        }
    }
}
