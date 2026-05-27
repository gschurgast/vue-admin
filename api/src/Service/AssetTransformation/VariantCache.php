<?php

namespace App\Service\AssetTransformation;

use League\Flysystem\FilesystemOperator;

/**
 * Read/write helper for the S3 (Flysystem) variant cache (Plan 03-03, D-20/D-21).
 *
 * Wraps the same `assets.storage` Flysystem used by Asset originals — works
 * identically against local FS in dev/test and against AWS S3 in prod.
 *
 * Keys are produced by {@see TransformationStorageKey::forVariant()}:
 *   transformations/{transformationId}-v{hash8}/{shard}/{assetId}.{ext}
 */
class VariantCache
{
    public function __construct(private readonly FilesystemOperator $storage) {}

    public function has(string $key): bool
    {
        return $this->storage->fileExists($key);
    }

    /**
     * @return resource
     */
    public function read(string $key)
    {
        return $this->storage->readStream($key);
    }

    public function write(string $key, string $bytes, string $contentType): void
    {
        $this->storage->write($key, $bytes, [
            'ContentType' => $contentType,
            'visibility' => 'public',
        ]);
    }

    public function delete(string $key): void
    {
        if ($this->storage->fileExists($key)) {
            $this->storage->delete($key);
        }
    }
}
