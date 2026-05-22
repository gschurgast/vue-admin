<?php

namespace App\Message;

/**
 * Dispatched after an asset is persisted to compute its image embedding
 * asynchronously and detect duplicates / near-duplicates.
 */
final readonly class ComputeEmbeddingMessage
{
    public function __construct(
        public int $assetId,
    ) {
    }
}
