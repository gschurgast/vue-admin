<?php

namespace App\Service\AssetTransformation;

/**
 * Pure helper for transformation variant S3/Flysystem keys.
 *
 * Format: transformations/{transformationId}-v{hash8}/{shard}/{assetId}.{ext}
 *
 * - hash8 = substr($versionHash, 0, 8) — first 8 hex chars of the sha1 versionHash.
 *           Storage uses short hash for readability ; the full 40-char hash stays in DB
 *           for traceability and collision avoidance.
 * - shard = intdiv($assetId, 1000) — same convention as Asset::computeS3Key()
 *           (api/src/Entity/Asset/Asset.php) — DIVERGENCE = cache miss cluster-wide.
 * - ext   = leading dot stripped.
 */
final class TransformationStorageKey
{
    public static function forVariant(
        int $transformationId,
        string $versionHash,
        int $assetId,
        string $ext,
    ): string {
        $shard = intdiv($assetId, 1000);
        $hash8 = substr($versionHash, 0, 8);
        $ext = ltrim($ext, '.');

        return sprintf(
            'transformations/%d-v%s/%d/%d.%s',
            $transformationId,
            $hash8,
            $shard,
            $assetId,
            $ext,
        );
    }

    /**
     * Directory prefix (no trailing slash) for all variants of (transformationId, versionHash).
     * Used by the purge handler in Phase 3+.
     */
    public static function prefixForVariants(int $transformationId, string $versionHash): string
    {
        return sprintf('transformations/%d-v%s', $transformationId, substr($versionHash, 0, 8));
    }
}
