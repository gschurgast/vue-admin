<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Params DTO for StepType::CROP.
 *
 * Two mutually exclusive shapes (Phase 2 contract):
 *   - xy-wh : { x:int>=0, y:int>=0, width:int>=1, height:int>=1 }
 *   - aspect: { aspectRatio: '\d+:\d+', anchor?: 'center'|'top'|'bottom'|'left'|'right' }
 */
final readonly class CropStepParams
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public ?int $x = null,
        #[Assert\PositiveOrZero]
        public ?int $y = null,
        #[Assert\Range(min: 1)]
        public ?int $width = null,
        #[Assert\Range(min: 1)]
        public ?int $height = null,
        #[Assert\Regex(pattern: '/^\d+:\d+$/')]
        public ?string $aspectRatio = null,
        #[Assert\Choice(choices: ['center', 'top', 'bottom', 'left', 'right'])]
        public ?string $anchor = null,
    ) {
    }

    #[Assert\Callback]
    public function validateExactlyOneShape(ExecutionContextInterface $ctx): void
    {
        $hasXywh = $this->x !== null || $this->y !== null
            || $this->width !== null || $this->height !== null;
        $hasAspect = $this->aspectRatio !== null || $this->anchor !== null;

        if ($hasXywh && $hasAspect) {
            $ctx->buildViolation('Crop accepts either xy-wh OR aspectRatio, not both.')
                ->atPath('aspectRatio')
                ->addViolation();
            return;
        }

        if (!$hasXywh && !$hasAspect) {
            $ctx->buildViolation('Crop requires either xy-wh or aspectRatio.')
                ->atPath('aspectRatio')
                ->addViolation();
            return;
        }

        if ($hasXywh) {
            // All four xy-wh fields required when using that shape.
            foreach (['x', 'y', 'width', 'height'] as $field) {
                if ($this->{$field} === null) {
                    $ctx->buildViolation(sprintf('xy-wh crop requires "%s".', $field))
                        ->atPath($field)
                        ->addViolation();
                }
            }
            return;
        }

        // aspect-shape branch: aspectRatio is mandatory (anchor alone is not enough).
        if ($this->aspectRatio === null) {
            $ctx->buildViolation('aspect crop requires "aspectRatio".')
                ->atPath('aspectRatio')
                ->addViolation();
        }
    }
}
