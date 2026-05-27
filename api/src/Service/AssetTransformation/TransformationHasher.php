<?php

namespace App\Service\AssetTransformation;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;

/**
 * Compute a deterministic sha1 hash over the ordered step list.
 *
 * Canonicalisation rules (v1 — NEVER change without coordinated cache invalidation):
 *   1. Steps ordered by position ASC.
 *   2. Each step → ['type' => $step->getType()->value, 'params' => canonicalizeParams(...)].
 *   3. canonicalizeParams: recursively drop null values, ksort keys.
 *   4. json_encode with JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR.
 *   5. sha1(<utf8 string>) → 40-char hex.
 *
 * NO algorithm-version prefix in v1.0. If canonicalisation rules change in v2, prepend "v2:" before sha1.
 */
final class TransformationHasher
{
    public function compute(AssetTransformation $transformation): string
    {
        $steps = $transformation->getSteps()->toArray();
        usort(
            $steps,
            static fn (TransformationStep $a, TransformationStep $b) => $a->getPosition() <=> $b->getPosition(),
        );

        $canonical = array_map(
            fn (TransformationStep $s) => [
                'type'   => $s->getType()?->value,
                'params' => $this->canonicalizeParams($s->getParams()),
            ],
            $steps,
        );

        return sha1(json_encode(
            $canonical,
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function canonicalizeParams(array $params): array
    {
        $filtered = [];
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $filtered[$key] = is_array($value) ? $this->canonicalizeParams($value) : $value;
        }
        ksort($filtered);
        return $filtered;
    }
}
