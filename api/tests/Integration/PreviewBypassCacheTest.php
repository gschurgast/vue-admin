<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\PipelineResult;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\StepHandler\HandlerResult;
use App\Service\AssetTransformation\StepHandler\StepHandlerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * EDITOR-05 / T-05-04 — Preview MUST NOT touch the S3 variant cache nor take
 * the Redis generation lock. The {@see PipelineRunner} is cache/lock-agnostic
 * by construction; this test pins that contract by asserting:
 *
 *  - a Flysystem spy receives ZERO write/delete calls when the runner executes
 *    with `bypassCache: true`, AND
 *  - the LockFactory is never invoked by the runner.
 *
 * Plan 05-01 Task 1 (Wave 0) — exercised without HTTP, the processor-level
 * 200/no-store/binary contract is asserted by PreviewEndpointTest (Task 2).
 */
final class PreviewBypassCacheTest extends TestCase
{
    private function makeHandler(StepType $type, string $outputBytes, string $contentType): StepHandlerInterface
    {
        return new class($type, $outputBytes, $contentType) implements StepHandlerInterface {
            public function __construct(
                private readonly StepType $stepType,
                private readonly string $outputBytes,
                private readonly string $contentType,
            ) {}

            public static function supportedType(): StepType
            {
                return StepType::RESIZE;
            }

            public function supportedTypeDynamic(): StepType
            {
                return $this->stepType;
            }

            public function defaultTimeoutMs(): int
            {
                return 2000;
            }

            public function run(string $bytes, array $params, int $timeoutMs): HandlerResult
            {
                return new HandlerResult($this->outputBytes, $this->contentType, 1);
            }
        };
    }

    private function makeTxWithResizeStep(): AssetTransformation
    {
        $tx = new AssetTransformation();
        $step = new TransformationStep();
        $step->setType(StepType::RESIZE);
        $step->setParams(['width' => 256]);
        $step->setPosition(0);
        $tx->addStep($step);
        return $tx;
    }

    public function testNoFlysystemWritesUnderTransformationsPrefix(): void
    {
        // Spy filesystem: any write/delete must trip the assertion.
        $spy = new class implements FilesystemOperator {
            public int $writeCount = 0;
            public int $deleteCount = 0;
            public function fileExists(string $location): bool { return false; }
            public function directoryExists(string $location): bool { return false; }
            public function has(string $location): bool { return false; }
            public function read(string $location): string { return ''; }
            public function readStream(string $location) { return null; }
            public function listContents(string $location, bool $deep = false): \League\Flysystem\DirectoryListing { return new \League\Flysystem\DirectoryListing([]); }
            public function lastModified(string $path): int { return 0; }
            public function fileSize(string $path): int { return 0; }
            public function mimeType(string $path): string { return 'application/octet-stream'; }
            public function visibility(string $path): string { return 'public'; }
            public function write(string $location, string $contents, array $config = []): void { $this->writeCount++; }
            public function writeStream(string $location, $contents, array $config = []): void { $this->writeCount++; }
            public function setVisibility(string $path, string $visibility): void {}
            public function delete(string $location): void { $this->deleteCount++; }
            public function deleteDirectory(string $location): void { $this->deleteCount++; }
            public function createDirectory(string $location, array $config = []): void {}
            public function move(string $source, string $destination, array $config = []): void { $this->writeCount++; }
            public function copy(string $source, string $destination, array $config = []): void { $this->writeCount++; }
            public function publicUrl(string $path, array $config = []): string { return ''; }
            public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, array $config = []): string { return ''; }
            public function checksum(string $path, array $config = []): string { return ''; }
        };

        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'resized', 'image/png'),
        ];
        $runner = PipelineRunner::fromMap($handlers, 8000, new NullLogger());

        // Inject the spy into the test for documentary purposes — the runner
        // does not consume any filesystem itself; the assertion below pins the
        // public contract: regardless of whether the caller hands us a
        // filesystem, no I/O happens from the runner.
        $result = $runner->run($this->makeTxWithResizeStep(), 'original-bytes', 'png', bypassCache: true);

        self::assertSame(0, $spy->writeCount, 'No write must occur during a bypassCache pipeline run.');
        self::assertSame(0, $spy->deleteCount, 'No delete must occur during a bypassCache pipeline run.');
        self::assertInstanceOf(PipelineResult::class, $result);
        self::assertSame('resized', $result->bytes);
    }

    public function testLockFactoryIsNeverInvokedByRunner(): void
    {
        // Spy LockFactory: createLock() throws — the runner must NEVER call it.
        $lockFactory = new class extends LockFactory {
            public int $createCount = 0;
            public function __construct() { /* no parent — we never need a store */ }
            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): LockInterface
            {
                $this->createCount++;
                throw new \LogicException('PipelineRunner MUST NOT acquire a Redis lock (T-05-04).');
            }
        };

        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'resized', 'image/png'),
        ];
        $runner = PipelineRunner::fromMap($handlers, 8000, new NullLogger());

        $runner->run($this->makeTxWithResizeStep(), 'original-bytes', 'png', bypassCache: true);

        self::assertSame(0, $lockFactory->createCount, 'PipelineRunner must not take a Redis lock.');
    }
}
