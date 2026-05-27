<?php

namespace App\Service\AssetTransformation\StepHandler;

/**
 * Immutable result returned by a {@see StepHandlerInterface}.
 *
 * - `bytes`       : binary payload returned by the embedder endpoint
 * - `contentType` : MIME type advertised by the embedder (image/jpeg, image/webp, …)
 * - `renderMs`    : render duration reported by the embedder via X-Render-Duration-Ms,
 *                   or wall-clock measured by the handler as fallback
 */
final readonly class HandlerResult
{
    public function __construct(
        public string $bytes,
        public string $contentType,
        public int $renderMs,
    ) {
    }
}
