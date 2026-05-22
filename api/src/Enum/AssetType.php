<?php

namespace App\Enum;

enum AssetType: string
{
    case IMAGE = 'image';
    case PDF = 'pdf';
    case VIDEO = 'video';
    case DOC = 'doc';

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => 'Image',
            self::PDF => 'PDF',
            self::VIDEO => 'Video',
            self::DOC => 'Document',
        };
    }

    /**
     * @return string[]
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::IMAGE => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'],
            self::PDF => ['application/pdf'],
            self::VIDEO => ['video/mp4', 'video/webm', 'video/quicktime'],
            self::DOC => [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'text/csv',
            ],
        };
    }

    /**
     * Max upload size in bytes.
     */
    public function maxSize(): int
    {
        return match ($this) {
            self::IMAGE => 10 * 1024 * 1024,       // 10 MB
            self::PDF => 50 * 1024 * 1024,         // 50 MB
            self::VIDEO => 500 * 1024 * 1024,      // 500 MB
            self::DOC => 25 * 1024 * 1024,         // 25 MB
        };
    }

    public static function fromMimeType(string $mimeType): ?self
    {
        foreach (self::cases() as $case) {
            if (in_array($mimeType, $case->allowedMimeTypes(), true)) {
                return $case;
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    public static function allCodes(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }
        return $out;
    }
}
