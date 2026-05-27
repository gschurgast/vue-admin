<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Params DTO for StepType::ADD_BACKGROUND.
 *
 * Phase 3 contract — TWO shapes (no URL accepted, SSRF-safe by construction):
 *   - { type: 'color',  color:   '#rrggbb' }
 *   - { type: 'asset',  assetId: int >= 1 }
 *
 * Note: `type: 'ai_prompt'` will be re-enabled in Phase 6 with its own DTO.
 * It is rejected here via the Choice constraint.
 */
final readonly class AddBackgroundStepParams
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Choice(choices: ['color', 'asset'])]
        public ?string $type = null,
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/')]
        public ?string $color = null,
        #[Assert\Range(min: 1)]
        public ?int $assetId = null,
    ) {
    }

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $ctx): void
    {
        if ($this->type === 'color') {
            if ($this->color === null) {
                $ctx->buildViolation('color is required when type=color.')
                    ->atPath('color')
                    ->addViolation();
            }
            if ($this->assetId !== null) {
                $ctx->buildViolation('assetId must be null when type=color.')
                    ->atPath('assetId')
                    ->addViolation();
            }
        } elseif ($this->type === 'asset') {
            if ($this->assetId === null) {
                $ctx->buildViolation('assetId is required when type=asset.')
                    ->atPath('assetId')
                    ->addViolation();
            }
            if ($this->color !== null) {
                $ctx->buildViolation('color must be null when type=asset.')
                    ->atPath('color')
                    ->addViolation();
            }
        }
    }
}
