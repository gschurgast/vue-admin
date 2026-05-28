<?php

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Enum\AssetType;
use App\Message\WarmupTransformationVariantMessage;
use App\MessageHandler\WarmupTransformationVariantHandler;
use App\Service\AssetTransformation\PipelineResult;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\TransformationStorageKey;
use App\Service\AssetTransformation\VariantCache;
use Doctrine\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Plan 05-02 — Task 1 unit test.
 *
 * Verifies the handler:
 *   1. Loads the AssetTransformation + Asset by id.
 *   2. Loads original bytes via Flysystem and pipes through PipelineRunner.
 *   3. Writes the variant to S3/Flysystem (warmup PRE-FILLS the cache, per A6 RESEARCH).
 *   4. Is idempotent on missing tx / missing asset (logs warning, returns silently).
 */
final class WarmupTransformationVariantHandlerTest extends TestCase
{
    public function testHandlerWritesVariantToCacheOnHappyPath(): void
    {
        $tx = $this->makeTransformation(12, 'a3f7c10b8d2e4f5a6b7c8d9e0f1a2b3c4d5e6f70');
        $asset = $this->makeAsset(42, '0/42.png');

        $txRepo = $this->makeRepoReturning(AssetTransformation::class, [12 => $tx]);
        $assetRepo = $this->makeRepoReturning(Asset::class, [42 => $asset]);
        $em = $this->makeEm([
            AssetTransformation::class => $txRepo,
            Asset::class => $assetRepo,
        ]);

        $runner = $this->createMock(PipelineRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->with($tx, 'PNG-BYTES', 'png')
            ->willReturn(new PipelineResult('OUT-BYTES', 'image/png', 42, [], ['resize']));

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->once())
            ->method('read')
            ->with('0/42.png')
            ->willReturn('PNG-BYTES');

        $cache = $this->createMock(VariantCache::class);
        $expectedKey = TransformationStorageKey::forVariant(12, 'a3f7c10b8d2e4f5a6b7c8d9e0f1a2b3c4d5e6f70', 42, 'png');
        $cache->expects($this->once())
            ->method('has')
            ->with($expectedKey)
            ->willReturn(false);
        $cache->expects($this->once())
            ->method('write')
            ->with($expectedKey, 'OUT-BYTES', 'image/png');

        $handler = new WarmupTransformationVariantHandler(
            $em,
            $runner,
            $cache,
            $storage,
            new NullLogger(),
        );

        $handler(new WarmupTransformationVariantMessage(12, 42, 'png'));
    }

    public function testHandlerIsIdempotentWhenVariantAlreadyCached(): void
    {
        $tx = $this->makeTransformation(12, 'a3f7c10b8d2e4f5a6b7c8d9e0f1a2b3c4d5e6f70');
        $asset = $this->makeAsset(42, '0/42.png');

        $em = $this->makeEm([
            AssetTransformation::class => $this->makeRepoReturning(AssetTransformation::class, [12 => $tx]),
            Asset::class => $this->makeRepoReturning(Asset::class, [42 => $asset]),
        ]);

        $runner = $this->createMock(PipelineRunner::class);
        $runner->expects($this->never())->method('run');

        $storage = $this->createMock(FilesystemOperator::class);
        $storage->expects($this->never())->method('read');

        $cache = $this->createMock(VariantCache::class);
        $cache->expects($this->once())->method('has')->willReturn(true);
        $cache->expects($this->never())->method('write');

        $handler = new WarmupTransformationVariantHandler(
            $em,
            $runner,
            $cache,
            $storage,
            new NullLogger(),
        );

        $handler(new WarmupTransformationVariantMessage(12, 42, 'png'));
    }

    public function testHandlerSilentlyExitsWhenTransformationMissing(): void
    {
        $em = $this->makeEm([
            AssetTransformation::class => $this->makeRepoReturning(AssetTransformation::class, []),
            Asset::class => $this->makeRepoReturning(Asset::class, []),
        ]);

        $runner = $this->createMock(PipelineRunner::class);
        $runner->expects($this->never())->method('run');

        $cache = $this->createMock(VariantCache::class);
        $cache->expects($this->never())->method('write');

        $storage = $this->createMock(FilesystemOperator::class);

        $handler = new WarmupTransformationVariantHandler(
            $em,
            $runner,
            $cache,
            $storage,
            new NullLogger(),
        );

        $handler(new WarmupTransformationVariantMessage(999, 42, 'png'));
    }

    // --- Helpers --------------------------------------------------------

    private function makeTransformation(int $id, string $hash): AssetTransformation
    {
        $tx = new AssetTransformation();
        $tx->setCode('product-thumb');
        $tx->setLabel('Product thumb');
        $tx->setVersionHash($hash);
        $r = new \ReflectionProperty(AssetTransformation::class, 'id');
        $r->setAccessible(true);
        $r->setValue($tx, $id);
        return $tx;
    }

    private function makeAsset(int $id, string $s3Key): Asset
    {
        $asset = new Asset();
        $asset->setCode('asset-' . $id);
        $asset->setType(AssetType::IMAGE);
        $asset->setMimeType('image/png');
        $asset->setFilename($id . '.png');
        $asset->setSize(1024);
        $asset->setS3Key($s3Key);
        $r = new \ReflectionProperty(Asset::class, 'id');
        $r->setAccessible(true);
        $r->setValue($asset, $id);
        return $asset;
    }

    /**
     * @param array<int, object> $byId
     */
    private function makeRepoReturning(string $class, array $byId): ObjectRepository
    {
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturnCallback(static fn ($id) => $byId[$id] ?? null);
        return $repo;
    }

    /**
     * @param array<class-string, ObjectRepository> $repos
     */
    private function makeEm(array $repos): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $repos[$class] ?? throw new \InvalidArgumentException("no repo for $class"),
        );
        return $em;
    }
}
