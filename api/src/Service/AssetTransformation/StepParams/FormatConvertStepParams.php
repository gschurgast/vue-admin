<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Params DTO for StepType::FORMAT_CONVERT.
 *
 * Phase 2 contract:
 *   { format: 'png'|'jpg'|'jpeg'|'webp'|'avif', quality?: int 1..100 }
 */
final readonly class FormatConvertStepParams
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Choice(choices: ['png', 'jpg', 'jpeg', 'webp', 'avif'])]
        public ?string $format = null,
        #[Assert\Range(min: 1, max: 100)]
        public ?int $quality = null,
    ) {
    }
}
