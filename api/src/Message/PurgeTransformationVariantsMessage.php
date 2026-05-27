<?php

namespace App\Message;

/**
 * Dispatched when an AssetTransformation's versionHash changes (steps modified)
 * OR when an AssetTransformation is deleted. The handler purges S3 variants under
 * the prefix `transformations/{transformationId}-v{hash8}/` where hash8 = substr(versionHash, 0, 8).
 *
 * Both fields are captured BEFORE flush (Pitfall C of RESEARCH) so the message
 * carries enough info even after the row is deleted.
 */
final readonly class PurgeTransformationVariantsMessage
{
    public function __construct(
        public int $transformationId,
        public string $versionHash,
    ) {
    }
}
