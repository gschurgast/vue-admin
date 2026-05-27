<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepParams;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Plan 04 — DTO validator for the `remove_background` step (BGREMOVE-01..06).
 *
 * Contract: `POST /img/remove-background` (Phase 4 Python endpoint, D-17).
 * Only two params are accepted; any extra key (e.g. URL) is rejected at the
 * StepParamsFactory denormalisation stage (ALLOW_EXTRA_ATTRIBUTES=false) → 422.
 */
final readonly class RemoveBackgroundStepParams
{
    public function __construct(
        #[Assert\Choice(choices: ['birefnet', 'isnet-general-use'])]
        public string $model = 'birefnet',
        public bool $fallbackOnTimeout = false,
    ) {
    }
}
