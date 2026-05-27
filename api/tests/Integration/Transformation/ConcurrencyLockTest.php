<?php

namespace App\Tests\Integration\Transformation;

use App\Controller\PublicTransformationController;
use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Enum\AssetType;
use App\Service\AssetTransformation\PipelineResult;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\TransformationLookup;
use App\Service\AssetTransformation\TransformationPipelineException;
use App\Service\AssetTransformation\VariantCache;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * SC3 GATING test (Plan 03-03 Task 3).
 *
 * Voie B (chosen): drive N sequential requests against the controller using
 * a Lock stub whose first acquire(false) succeeds and subsequent ones return
 * false. The VariantCache stub flips from miss→hit between requests to
 * simulate the inter-process race condition deterministically.
 *
 * Why not Voie A (pcntl_fork): the test runs inside the api container; spawning
 * children via pcntl_fork inside phpunit leaks file descriptors and breaks
 * DBAL connections. Voie B exercises the exact same code paths in the
 * controller — acquire(false) waiter behaviour + cache re-check — without any
 * inter-process fragility.
 */
#[IgnoreDeprecations]
final class ConcurrencyLockTest extends TestCase
{
    public function testConcurrentColdRequestsGenerateOnce(): void
    {
        $tx = $this->makeTx();
        $asset = $this->makeAsset(true);

        // Cache flips: miss for the first call (the generator), then hit for
        // every subsequent waiter that re-checks S3 after acquire(false) fails.
        $cache = $this->makeCacheFlipsOnGeneration();

        // PipelineRunner counter — incremented exactly once across N requests.
        $runner = new SpyRunner(new PipelineResult('OUT', 'image/png', 1, [], []));

        // Lock: first acquire(false) → true (generator). All subsequent
        // acquire(false) → false (waiters).
        $lockFactory = $this->makeOnceThenContendedLockFactory();

        $controller = $this->makeController($tx, $asset, $cache, $runner, $lockFactory);

        $statuses = [];
        for ($i = 0; $i < 5; $i++) {
            $response = $controller->serve('thumb-200', 1, 'png', new Request());
            $statuses[] = $response->getStatusCode();
        }

        $this->assertSame(1, $runner->calls, 'PipelineRunner must run exactly once across N concurrent requests');
        $this->assertContains(Response::HTTP_OK, $statuses);
        foreach ($statuses as $s) {
            $this->assertContains($s, [Response::HTTP_OK, Response::HTTP_SERVICE_UNAVAILABLE]);
        }
    }

    public function testSecondRequestServedFromCacheWithoutRunner(): void
    {
        $tx = $this->makeTx();
        $asset = $this->makeAsset(true);

        $cache = $this->makeCacheFlipsOnGeneration();
        $runner = new SpyRunner(new PipelineResult('OUT', 'image/png', 1, [], []));
        $lockFactory = $this->makeAlwaysAcquireLockFactory();

        $controller = $this->makeController($tx, $asset, $cache, $runner, $lockFactory);

        $first = $controller->serve('thumb-200', 1, 'png', new Request());
        $second = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_OK, $first->getStatusCode());
        $this->assertSame(Response::HTTP_OK, $second->getStatusCode());
        $this->assertSame(1, $runner->calls, '2nd request must be served from cache');

        // ETag identical across cache miss (1st) and cache hit (2nd) — D-19.
        $this->assertSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function testCapExceededReturns503WithRetryAfter(): void
    {
        $tx = $this->makeTx();
        $asset = $this->makeAsset(true);

        $cache = new SpyVariantCache(hasSequence: false); // always miss
        $runner = new SpyRunner(throw: new TransformationPipelineException('cap', TransformationPipelineException::CODE_CAP_EXCEEDED));
        $lockFactory = $this->makeAlwaysAcquireLockFactory();

        $controller = $this->makeController($tx, $asset, $cache, $runner, $lockFactory);

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('2', $response->headers->get('Retry-After'));
    }

    public function testEtagIdenticalAcrossPaths(): void
    {
        $tx = $this->makeTx(versionHash: 'deadbeefcafef00d0000000000000000aaaaaaaa');
        $asset = $this->makeAsset(true);

        $cache = $this->makeCacheFlipsOnGeneration();
        $runner = new SpyRunner(new PipelineResult('B', 'image/png', 1, [], []));
        $lockFactory = $this->makeAlwaysAcquireLockFactory();

        $controller = $this->makeController($tx, $asset, $cache, $runner, $lockFactory);

        $missResponse = $controller->serve('thumb-200', 1, 'png', new Request());
        $hitResponse = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertMatchesRegularExpression(
            '/^"\d+-vdeadbeef-\d+-png"$/',
            (string) $missResponse->headers->get('ETag'),
        );
        $this->assertSame($missResponse->headers->get('ETag'), $hitResponse->headers->get('ETag'));
    }

    public function testWaiterReceives503WhenCacheStaysCold(): void
    {
        $tx = $this->makeTx();
        $asset = $this->makeAsset(true);

        // Cache always reports miss; waiter loop ends in 503.
        $cache = new SpyVariantCache(hasSequence: false);
        $runner = new SpyRunner(new PipelineResult('B', 'image/png', 1, [], []));

        // Lock always returns false for acquire(false) — pure waiter path.
        $lockFactory = new class extends LockFactory {
            public function __construct() {}
            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                return new class implements SharedLockInterface {
                    public function acquire(bool $blocking = false): bool { return false; }
                    public function acquireRead(bool $blocking = false): bool { return false; }
                    public function refresh(?float $ttl = null): void {}
                    public function isAcquired(): bool { return false; }
                    public function release(): void {}
                    public function isExpired(): bool { return false; }
                    public function getRemainingLifetime(): ?float { return null; }
                };
            }
        };

        $controller = $this->makeController($tx, $asset, $cache, $runner, $lockFactory);

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('2', $response->headers->get('Retry-After'));
        $this->assertSame(0, $runner->calls, 'Waiter must NOT invoke the runner');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function makeController(
        AssetTransformation $tx,
        Asset $asset,
        VariantCache $cache,
        PipelineRunner $runner,
        LockFactory $lockFactory,
    ): PublicTransformationController {
        $lookup = new class($tx, $asset) extends TransformationLookup {
            public function __construct(private readonly AssetTransformation $tx, private readonly Asset $asset) {}
            public function findOr404(string $code, int $assetId): array
            {
                return [$this->tx, $this->asset];
            }
        };

        $storage = $this->createStub(FilesystemOperator::class);
        $storage->method('read')->willReturn('ORIGINAL');

        return new PublicTransformationController(
            enabled: true,
            lookup: $lookup,
            cache: $cache,
            assetsStorage: $storage,
            lockFactory: $lockFactory,
            runner: $runner,
            logger: new NullLogger(),
        );
    }

    private function makeTx(string $versionHash = '1234567890abcdef0000000000000000aaaaaaaa'): AssetTransformation
    {
        $tx = new AssetTransformation();
        $tx->setCode('thumb-200');
        $tx->setLabel('Thumb 200');
        $tx->setVersionHash($versionHash);
        (new \ReflectionProperty($tx, 'id'))->setValue($tx, 7);
        return $tx;
    }

    private function makeAsset(bool $isPublic, int $id = 1): Asset
    {
        $asset = new Asset();
        $asset->setType(AssetType::IMAGE);
        $asset->setMimeType('image/png');
        $asset->setFilename('a.png');
        $asset->setS3Key('0/1.png');
        $asset->setIsPublic($isPublic);
        (new \ReflectionProperty($asset, 'id'))->setValue($asset, $id);
        return $asset;
    }

    /**
     * SpyVariantCache that returns miss until write() is invoked, then hit
     * forever. Simulates "the generator just wrote to S3" deterministically.
     */
    private function makeCacheFlipsOnGeneration(): SpyVariantCache
    {
        return new SpyVariantCache(hasSequence: 'flips-on-write');
    }

    private function makeAlwaysAcquireLockFactory(): LockFactory
    {
        return new class extends LockFactory {
            public function __construct() {}
            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                return new class implements SharedLockInterface {
                    public function acquire(bool $blocking = false): bool { return true; }
                    public function acquireRead(bool $blocking = false): bool { return true; }
                    public function refresh(?float $ttl = null): void {}
                    public function isAcquired(): bool { return true; }
                    public function release(): void {}
                    public function isExpired(): bool { return false; }
                    public function getRemainingLifetime(): ?float { return null; }
                };
            }
        };
    }

    /**
     * First acquire(false) returns true (generator wins). All later
     * acquire(false) return false (waiters). Shared state across all locks
     * created by the same factory.
     */
    private function makeOnceThenContendedLockFactory(): LockFactory
    {
        $state = new \stdClass();
        $state->granted = false;

        return new class($state) extends LockFactory {
            public function __construct(private readonly \stdClass $state) {}
            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                return new class($this->state) implements SharedLockInterface {
                    public function __construct(private readonly \stdClass $state) {}
                    public function acquire(bool $blocking = false): bool
                    {
                        if (!$this->state->granted) {
                            $this->state->granted = true;
                            return true;
                        }
                        return false;
                    }
                    public function acquireRead(bool $blocking = false): bool { return $this->acquire($blocking); }
                    public function refresh(?float $ttl = null): void {}
                    public function isAcquired(): bool { return $this->state->granted; }
                    public function release(): void {}
                    public function isExpired(): bool { return false; }
                    public function getRemainingLifetime(): ?float { return null; }
                };
            }
        };
    }
}

// ------------------------------------------------------------------
// Spies
// ------------------------------------------------------------------

class SpyVariantCache extends VariantCache
{
    public int $hasCalls = 0;
    public int $readCalls = 0;
    /** @var list<array{key:string,bytes:string,contentType:string}> */
    public array $writes = [];

    private bool $written = false;
    private bool|string $hasSequence;

    public function __construct(bool|string $hasSequence)
    {
        $this->hasSequence = $hasSequence;
    }

    public function has(string $key): bool
    {
        $this->hasCalls++;
        if (is_bool($this->hasSequence)) {
            return $this->hasSequence;
        }
        // 'flips-on-write': miss until a write happens, then hit.
        return $this->written;
    }

    public function read(string $key)
    {
        $this->readCalls++;
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open memory stream');
        }
        fwrite($stream, 'BYTES');
        rewind($stream);
        return $stream;
    }

    public function write(string $key, string $bytes, string $contentType): void
    {
        $this->writes[] = ['key' => $key, 'bytes' => $bytes, 'contentType' => $contentType];
        $this->written = true;
    }

    public function delete(string $key): void
    {
        $this->written = false;
    }
}

class SpyRunner extends PipelineRunner
{
    public int $calls = 0;
    public function __construct(
        private readonly ?PipelineResult $result = null,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function run(AssetTransformation $tx, string $bytes, string $outputExt): PipelineResult
    {
        $this->calls++;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        if ($this->result === null) {
            throw new \LogicException('SpyRunner must be configured with either $result or $throw');
        }
        return $this->result;
    }
}
