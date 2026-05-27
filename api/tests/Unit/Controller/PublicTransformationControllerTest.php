<?php

namespace App\Tests\Unit\Controller;

use App\Controller\PublicTransformationController;
use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\AssetType;
use App\Enum\StepType;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Unit tests for PublicTransformationController (Plan 03-03 Task 2).
 *
 * The controller is instantiated directly with fake collaborators (no Kernel
 * boot) so each branch is asserted in isolation: feature flag, cache hit,
 * cache miss + generation, lock contention, error mapping, ETag, warnings.
 */
#[IgnoreDeprecations]
final class PublicTransformationControllerTest extends TestCase
{
    public function testFeatureFlagOffReturns404WithoutLookupOrPipeline(): void
    {
        $lookup = $this->stubLookup($this->makeTx(), $this->makeAsset(true));
        $cache = $this->stubCache(['has' => true]);
        $runner = $this->stubRunner();
        $controller = $this->makeController(
            enabled: false,
            lookup: $lookup,
            cache: $cache,
            runner: $runner,
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertCacheControlEquals('public, max-age=300', $response->headers->get('Cache-Control'));
        $this->assertSame(0, $lookup->calls);
        $this->assertSame(0, $runner->calls);
    }

    public function testMissingAssetReturns404(): void
    {
        $lookup = new class extends StubLookup {
            public function findOr404(string $code, int $assetId): array
            {
                $this->calls++;
                throw new NotFoundHttpException();
            }
        };
        $controller = $this->makeController(
            enabled: true,
            lookup: $lookup,
            cache: $this->stubCache(['has' => true]),
            runner: $this->stubRunner(),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(1, $lookup->calls);
    }

    public function testCacheHitStreamsWithImmutableHeaders(): void
    {
        $tx = $this->makeTx(versionHash: 'abcdef0123456789aaaaaaaaaaaaaaaaaaaaaaaa');
        $asset = $this->makeAsset(true);
        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($tx, $asset),
            cache: $this->stubCache(['has' => true]),
            runner: $runner = $this->stubRunner(),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCacheControlEquals('public, max-age=31536000, immutable', $response->headers->get('Cache-Control'));
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('cross-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        // ETag is deterministic: "{txId}-v{hash8}-{assetId}-{ext}"
        $this->assertMatchesRegularExpression(
            '/^"\d+-vabcdef01-\d+-png"$/',
            (string) $response->headers->get('ETag'),
        );
        $this->assertSame(0, $runner->calls); // pipeline not invoked on cache hit
    }

    public function testCacheMissGeneratesAndCachesThenReturns200(): void
    {
        $tx = $this->makeTx();
        $asset = $this->makeAsset(true);
        // miss (initial), still miss (re-check under lock), then hit (after streamFromCache write).
        $cache = $this->stubCache(['has' => [false, false, true]]);
        $lock = $this->stubLock(['acquire' => true]);
        $runner = $this->stubRunner(result: new PipelineResult('PNGBYTES', 'image/png', 12, [], ['resize']));

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($tx, $asset),
            cache: $cache,
            runner: $runner,
            lockFactory: $this->stubLockFactory($lock),
            assetsStorage: $this->stubAssetsStorage('ORIGINAL'),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame(1, $runner->calls);
        $this->assertCount(1, $cache->writes);
        $this->assertSame('PNGBYTES', $cache->writes[0]['bytes']);
        // Lock released BEFORE streaming (W5)
        $this->assertGreaterThanOrEqual(1, $lock->releaseCalls);
    }

    public function testCacheMissAcquireFailsThenCacheAppearsAndReturns200(): void
    {
        // Lock acquire fails (another worker is generating). The controller
        // probes cache once before returning 503 — if the cache has just
        // landed, serve it. has() sequence: first false (initial miss check),
        // then true (post-lock-fail probe).
        $cache = $this->stubCache(['has' => [false, true]]);
        $lock = $this->stubLock(['acquire' => false]);

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($this->makeTx(), $this->makeAsset(true)),
            cache: $cache,
            runner: $this->stubRunner(),
            lockFactory: $this->stubLockFactory($lock),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testCacheMissAcquireFailsAndCacheNeverAppearsReturns503(): void
    {
        // When lock acquire fails and the single cache re-probe still misses,
        // return 503 + Retry-After immediately. No busy-wait.
        $cache = $this->stubCache(['has' => false]);
        $lock = $this->stubLock(['acquire' => false]);

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($this->makeTx(), $this->makeAsset(true)),
            cache: $cache,
            runner: $this->stubRunner(),
            lockFactory: $this->stubLockFactory($lock),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('2', $response->headers->get('Retry-After'));
    }

    public function testPipelineCapExceededReturns503WithRetryAfter(): void
    {
        $runner = $this->stubRunner(throw: new TransformationPipelineException('cap', TransformationPipelineException::CODE_CAP_EXCEEDED));

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($this->makeTx(), $this->makeAsset(true)),
            cache: $this->stubCache(['has' => false]),
            runner: $runner,
            lockFactory: $this->stubLockFactory($this->stubLock(['acquire' => true])),
            assetsStorage: $this->stubAssetsStorage('X'),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());
        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        $this->assertSame('2', $response->headers->get('Retry-After'));
    }

    public function testPipelineEmbedderErrorReturns502NoRetryAfter(): void
    {
        $runner = $this->stubRunner(throw: new TransformationPipelineException('embedder', TransformationPipelineException::CODE_EMBEDDER_ERROR));

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($this->makeTx(), $this->makeAsset(true)),
            cache: $this->stubCache(['has' => false]),
            runner: $runner,
            lockFactory: $this->stubLockFactory($this->stubLock(['acquire' => true])),
            assetsStorage: $this->stubAssetsStorage('X'),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());
        $this->assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        $this->assertNull($response->headers->get('Retry-After'));
    }

    public function testIfNoneMatchReturns304WithoutS3OrPipeline(): void
    {
        $tx = $this->makeTx(versionHash: 'cafebabe1234567890aaaaaaaaaaaaaaaaaaaaaa');
        $asset = $this->makeAsset(true, id: 42);
        $cache = $this->stubCache(['has' => true]); // would also be a cache hit, but ETag wins
        $runner = $this->stubRunner();

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($tx, $asset),
            cache: $cache,
            runner: $runner,
        );

        $expectedEtag = sprintf('"%d-vcafebabe-42-png"', $tx->getId());
        $request = new Request();
        $request->headers->set('If-None-Match', $expectedEtag);

        $response = $controller->serve('thumb-200', 42, 'png', $request);

        $this->assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
        $this->assertSame($expectedEtag, $response->headers->get('ETag'));
        $this->assertSame(0, $cache->hasCalls); // cache.has not consulted
        $this->assertSame(0, $runner->calls);
    }

    public function testWarningsExposedAsHeader(): void
    {
        $tx = $this->makeTx();
        $tx->setWarnings([
            ['code' => 'alpha-flatten-on-jpeg', 'stepIndex' => null],
            ['code' => 'foo', 'stepIndex' => 1],
        ]);
        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($tx, $this->makeAsset(true)),
            cache: $this->stubCache(['has' => true]),
            runner: $this->stubRunner(),
        );

        $response = $controller->serve('thumb-200', 1, 'jpg', new Request());

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('alpha-flatten-on-jpeg, foo', $response->headers->get('X-Transformation-Warnings'));
    }

    public function testStreamCallableIsLazy(): void
    {
        // The StreamedResponse must NOT consume the cache stream during the
        // handler — fpassthru is only triggered when the response is sent.
        $cache = $this->stubCache(['has' => true]);
        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($this->makeTx(), $this->makeAsset(true)),
            cache: $cache,
            runner: $this->stubRunner(),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(0, $cache->readCalls); // read() not yet invoked
    }

    public function testLockReleaseOrderingW5(): void
    {
        // Verify W5: release() invoked BEFORE the stream is consumed.
        $cache = $this->stubCache(['has' => [false, false, true]]);
        $lock = $this->stubLock(['acquire' => true]);
        $runner = $this->stubRunner(result: new PipelineResult('B', 'image/png', 1, [], []));

        $controller = $this->makeController(
            enabled: true,
            lookup: $this->stubLookup($this->makeTx(), $this->makeAsset(true)),
            cache: $cache,
            runner: $runner,
            lockFactory: $this->stubLockFactory($lock),
            assetsStorage: $this->stubAssetsStorage('OR'),
        );

        $response = $controller->serve('thumb-200', 1, 'png', new Request());

        // Lock release happened during the handler return, before the
        // StreamedResponse callback runs.
        $this->assertGreaterThanOrEqual(1, $lock->releaseCalls);
        $this->assertSame(0, $cache->readCalls); // callback not invoked yet
        // Stream is still ready to deliver.
        $this->assertInstanceOf(StreamedResponse::class, $response);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeController(
        bool $enabled,
        TransformationLookup $lookup,
        VariantCache $cache,
        PipelineRunner $runner,
        ?LockFactory $lockFactory = null,
        ?FilesystemOperator $assetsStorage = null,
    ): PublicTransformationController {
        return new PublicTransformationController(
            enabled: $enabled,
            lookup: $lookup,
            cache: $cache,
            assetsStorage: $assetsStorage ?? $this->stubAssetsStorage(''),
            lockFactory: $lockFactory ?? $this->stubLockFactory($this->stubLock(['acquire' => true])),
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
        // Force an id without persistence
        $r = new \ReflectionProperty($tx, 'id');
        $r->setValue($tx, 7);
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
        $r = new \ReflectionProperty($asset, 'id');
        $r->setValue($asset, $id);
        return $asset;
    }

    private function stubLookup(AssetTransformation $tx, Asset $asset): StubLookup
    {
        return new class($tx, $asset) extends StubLookup {
            public function __construct(private readonly AssetTransformation $tx, private readonly Asset $asset) {}
            public function findOr404(string $code, int $assetId): array
            {
                $this->calls++;
                return [$this->tx, $this->asset];
            }
        };
    }

    /**
     * @param array{has: bool|list<bool>} $opts
     */
    private function stubCache(array $opts): StubVariantCache
    {
        return new StubVariantCache($opts['has']);
    }

    private function stubRunner(?PipelineResult $result = null, ?\Throwable $throw = null): StubRunner
    {
        return new StubRunner(
            $result ?? new PipelineResult('OUT', 'image/png', 1, [], []),
            $throw,
        );
    }

    /**
     * @param array{acquire: bool} $opts
     */
    private function stubLock(array $opts): StubLock
    {
        return new StubLock($opts['acquire']);
    }

    private function stubLockFactory(StubLock $lock): LockFactory
    {
        return new class($lock) extends LockFactory {
            public function __construct(private readonly StubLock $lock)
            {
                // skip parent ctor — we only need createLock
            }
            public function createLock(string $resource, ?float $ttl = 300.0, bool $autoRelease = true): SharedLockInterface
            {
                return $this->lock;
            }
        };
    }

    /**
     * Symfony normalises Cache-Control directive order alphabetically when
     * read back via Response::headers — comparing by tokenized sets keeps
     * the test stable across Symfony minor versions.
     */
    private function assertCacheControlEquals(string $expected, ?string $actual): void
    {
        $norm = static function (?string $cc): array {
            $cc ??= '';
            $parts = array_map('trim', explode(',', $cc));
            sort($parts);
            return $parts;
        };
        $this->assertSame($norm($expected), $norm($actual));
    }

    private function stubAssetsStorage(string $content): FilesystemOperator
    {
        $stub = $this->createStub(FilesystemOperator::class);
        $stub->method('read')->willReturn($content);
        return $stub;
    }
}

// ------------------------------------------------------------------
// Stubs (top-level classes so they can be extended via anonymous subs)
// ------------------------------------------------------------------

class StubLookup extends TransformationLookup
{
    public int $calls = 0;
    public function __construct() {}
    public function findOr404(string $code, int $assetId): array
    {
        $this->calls++;
        throw new \LogicException('Override me');
    }
}

class StubVariantCache extends VariantCache
{
    public int $hasCalls = 0;
    public int $readCalls = 0;
    /** @var list<array{key:string,bytes:string,contentType:string}> */
    public array $writes = [];

    /** @var bool|list<bool> */
    private bool|array $hasSequence;
    private int $hasIdx = 0;

    public function __construct(bool|array $has)
    {
        $this->hasSequence = $has;
    }

    public function has(string $key): bool
    {
        $this->hasCalls++;
        if (is_bool($this->hasSequence)) {
            return $this->hasSequence;
        }
        $idx = min($this->hasIdx++, count($this->hasSequence) - 1);
        return $this->hasSequence[$idx];
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
    }

    public function delete(string $key): void {}
}

class StubRunner extends PipelineRunner
{
    public int $calls = 0;

    public function __construct(
        private readonly PipelineResult $result,
        private readonly ?\Throwable $throw = null,
    ) {}

    public function run(AssetTransformation $tx, string $bytes, string $outputExt): PipelineResult
    {
        $this->calls++;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->result;
    }
}

class StubLock implements SharedLockInterface
{
    public int $releaseCalls = 0;

    public function __construct(private readonly bool $acquireResult) {}
    public function acquire(bool $blocking = false): bool { return $this->acquireResult; }
    public function acquireRead(bool $blocking = false): bool { return $this->acquireResult; }
    public function refresh(?float $ttl = null): void {}
    public function isAcquired(): bool { return $this->acquireResult; }
    public function release(): void { $this->releaseCalls++; }
    public function isExpired(): bool { return false; }
    public function getRemainingLifetime(): ?float { return null; }
}
