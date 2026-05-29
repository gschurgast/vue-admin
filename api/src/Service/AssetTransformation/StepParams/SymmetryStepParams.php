<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Params DTO for StepType::SYMMETRY.
 *
 * Contract :
 *   { axis: 'horizontal' | 'vertical' }
 *
 *  - horizontal : miroir gauche/droite (Pillow ImageOps.mirror)
 *  - vertical   : miroir haut/bas    (Pillow ImageOps.flip)
 */
final readonly class SymmetryStepParams
{
    public const AXIS_HORIZONTAL = 'horizontal';
    public const AXIS_VERTICAL = 'vertical';

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: [self::AXIS_HORIZONTAL, self::AXIS_VERTICAL])]
        public ?string $axis = null,
    ) {
    }
}