<?php

namespace App\Message;

/**
 * Dispatched by `transformations:warm {code} --asset-id=N` (Plan 05-03) to
 * pre-fill the variant cache for a given (transformation, asset, ext) triple.
 *
 * Routed on the dedicated `transformations` Messenger transport (Plan 05-02,
 * OPS-03). The handler runs `PipelineRunner` and writes the variant binary to
 * the S3/Flysystem cache (warmup ALWAYS writes, idempotent on cache hit).
 *
 * `ext` defaults to `png` (the lossless default for transformations whose last
 * step is not a `format_convert`). The caller may pass `jpg`/`webp`/etc. when
 * the transformation chain ends with an explicit format_convert step.
 */
final readonly class WarmupTransformationVariantMessage
{
    public function __construct(
        public int $transformationId,
        public int $assetId,
        public string $ext = 'png',
    ) {
    }
}
