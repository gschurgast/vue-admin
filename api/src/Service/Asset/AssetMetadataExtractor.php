<?php

namespace App\Service\Asset;

use App\Enum\AssetType;
use getID3;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Extracts technical metadata (width, height, duration) from an uploaded file
 * **without uploading** — we always read from the local temp path before the
 * file is streamed to storage.
 *
 * All extraction failures are caught and logged: metadata is best-effort and
 * must never break an upload.
 */
class AssetMetadataExtractor
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{width:?int, height:?int, duration:?int}
     */
    public function extract(string $localPath, AssetType $type, string $mimeType): array
    {
        try {
            return match ($type) {
                AssetType::IMAGE => $this->extractImage($localPath, $mimeType),
                AssetType::VIDEO => $this->extractVideo($localPath),
                default => $this->empty(),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Asset metadata extraction failed: {message}', [
                'message' => $e->getMessage(),
                'path' => $localPath,
                'type' => $type->value,
            ]);
            return $this->empty();
        }
    }

    /**
     * @return array{width:?int, height:?int, duration:null}
     */
    private function extractImage(string $localPath, string $mimeType): array
    {
        if ($mimeType === 'image/svg+xml') {
            return $this->extractSvg($localPath);
        }

        $info = @getimagesize($localPath);
        if ($info === false) {
            return $this->empty();
        }
        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'duration' => null,
        ];
    }

    /**
     * @return array{width:?int, height:?int, duration:null}
     */
    private function extractSvg(string $localPath): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_file($localPath);
            if ($xml === false) {
                return $this->empty();
            }
            $attrs = $xml->attributes();
            $width = isset($attrs['width']) ? $this->parseSvgLength((string) $attrs['width']) : null;
            $height = isset($attrs['height']) ? $this->parseSvgLength((string) $attrs['height']) : null;

            // viewBox fallback
            if (($width === null || $height === null) && isset($attrs['viewBox'])) {
                $parts = preg_split('/[\s,]+/', trim((string) $attrs['viewBox']));
                if ($parts !== false && count($parts) === 4) {
                    $width ??= (int) round((float) $parts[2]);
                    $height ??= (int) round((float) $parts[3]);
                }
            }

            return ['width' => $width, 'height' => $height, 'duration' => null];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function parseSvgLength(string $value): ?int
    {
        if (!preg_match('/^([\d.]+)/', $value, $m)) {
            return null;
        }
        return (int) round((float) $m[1]);
    }

    /**
     * @return array{width:?int, height:?int, duration:?int}
     */
    private function extractVideo(string $localPath): array
    {
        $getID3 = new getID3();
        $info = $getID3->analyze($localPath);

        $width = isset($info['video']['resolution_x']) ? (int) $info['video']['resolution_x'] : null;
        $height = isset($info['video']['resolution_y']) ? (int) $info['video']['resolution_y'] : null;
        $duration = isset($info['playtime_seconds']) ? (int) round((float) $info['playtime_seconds']) : null;

        return ['width' => $width, 'height' => $height, 'duration' => $duration];
    }

    /**
     * @return array{width:null, height:null, duration:null}
     */
    private function empty(): array
    {
        return ['width' => null, 'height' => null, 'duration' => null];
    }
}
