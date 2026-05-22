<?php

namespace App\Service\Asset;

use App\Entity\Asset\Asset;

/**
 * Result of an upload attempt.
 *
 * - $duplicate=true means the binary already existed (same SHA-256 checksum);
 *   the returned $asset is the pre-existing one, no new row was created.
 */
final readonly class AssetUploadResult
{
    public function __construct(
        public Asset $asset,
        public bool $duplicate,
    ) {
    }
}
