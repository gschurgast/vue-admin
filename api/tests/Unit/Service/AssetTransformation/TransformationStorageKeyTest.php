<?php

namespace App\Tests\Unit\Service\AssetTransformation;

use App\Service\AssetTransformation\TransformationStorageKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransformationStorageKeyTest extends TestCase
{
    public function testStandardVariantKey(): void
    {
        $key = TransformationStorageKey::forVariant(
            transformationId: 42,
            versionHash: 'abc12345defghijklmnopqrstuvwxyz1234567890',
            assetId: 1234,
            ext: 'webp',
        );
        self::assertSame('transformations/42-vabc12345/1/1234.webp', $key);
    }

    public function testExtensionWithLeadingDotIsStripped(): void
    {
        $a = TransformationStorageKey::forVariant(1, 'hash12345678901234567890123456789012abcd', 500, 'jpg');
        $b = TransformationStorageKey::forVariant(1, 'hash12345678901234567890123456789012abcd', 500, '.jpg');
        self::assertSame($a, $b);
        self::assertSame('transformations/1-vhash1234/0/500.jpg', $a);
    }

    #[DataProvider('shardCases')]
    public function testShardComputation(int $assetId, int $expectedShard): void
    {
        $key = TransformationStorageKey::forVariant(1, str_repeat('a', 40), $assetId, 'png');
        self::assertSame(
            sprintf('transformations/1-v%s/%d/%d.png', str_repeat('a', 8), $expectedShard, $assetId),
            $key,
        );
    }

    public static function shardCases(): array
    {
        return [
            [0, 0], [1, 0], [999, 0],
            [1000, 1], [1234, 1], [1999, 1],
            [15234, 15],
            [1_000_000, 1000],
        ];
    }

    public function testHash8IsAlwaysFirst8Chars(): void
    {
        $key = TransformationStorageKey::forVariant(7, '0123456789abcdef0123456789abcdef01234567', 42, 'png');
        self::assertStringContainsString('-v01234567/', $key);
    }

    public function testPrefixForVariants(): void
    {
        self::assertSame(
            'transformations/42-vabc12345',
            TransformationStorageKey::prefixForVariants(42, 'abc12345xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'),
        );
    }
}
