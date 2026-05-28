<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ApiResource\PreviewRequest;
use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\PipelineResult;
use App\Service\AssetTransformation\PipelineRunner;
use App\State\PreviewRequestProcessor;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\Policy\NoLimiter;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Validation;

/**
 * EDITOR-04 — POST /api/asset_transformations/preview returns:
 *   - 200 + Content-Type image/{ext} + binary body
 *   - Cache-Control: no-store
 *   - X-Robots-Tag: noindex
 *   - 401 when unauthenticated
 *   - 404 STRICT when target asset.isPublic === false (T-05-03)
 *
 * The processor is exercised directly with stubs (no Kernel boot) — same
 * pattern as {@see \App\Tests\Integration\Transformation\ConcurrencyLockTest}.
 */
final class PreviewEndpointTest extends TestCase
{
    private function makeRunner(string $outputBytes): PipelineRunner
    {
        // The runner is replaced by a thin double: it returns a canned
        // PipelineResult instead of invoking real handlers.
        return new class($outputBytes) extends PipelineRunner {
            public function __construct(private readonly string $outputBytes)
            {
                // Skip parent ctor — we override run() entirely.
            }
            public function run(AssetTransformation $tx, string $bytes, string $outputExt, bool $bypassCache = false): PipelineResult
            {
                if (!$bypassCache) {
                    throw new \LogicException('Preview must call run() with bypassCache=true.');
                }
                return new PipelineResult($this->outputBytes, 'image/png', 1, [], ['resize']);
            }
        };
    }

    private function makePublicAsset(int $id = 1): Asset
    {
        $asset = new Asset();
        // Reflect id (Asset has no public setId).
        $rc = new \ReflectionClass($asset);
        $prop = $rc->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($asset, $id);
        $asset->setIsPublic(true);
        $asset->setS3Key('0/1.png');
        return $asset;
    }

    private function makePrivateAsset(int $id = 2): Asset
    {
        $asset = $this->makePublicAsset($id);
        $asset->setIsPublic(false);
        return $asset;
    }

    /**
     * EntityManager double exposing only find(Asset::class, $id).
     * @param array<int, Asset|null> $assetsById
     */
    private function makeEm(array $assetsById): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(function (string $class, int $id) use ($assetsById) {
            if ($class !== Asset::class) {
                return null;
            }
            return $assetsById[$id] ?? null;
        });
        return $em;
    }

    private function makeStorage(string $bytes): FilesystemOperator
    {
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('read')->willReturn($bytes);
        $fs->method('fileExists')->willReturn(true);
        return $fs;
    }

    private function makeLimiterFactory(bool $accepted = true): RateLimiterFactoryInterface
    {
        // A factory that returns a limiter whose consume(1) is always accepted
        // (NoLimiter from symfony/rate-limiter Policy package).
        return new class($accepted) implements RateLimiterFactoryInterface {
            public function __construct(private readonly bool $accepted) {}
            public function create(?string $key = null): LimiterInterface
            {
                $accepted = $this->accepted;
                return new class($accepted) implements LimiterInterface {
                    public function __construct(private readonly bool $accepted) {}
                    public function consume(int $tokens = 1): RateLimit
                    {
                        $retryAfter = new \DateTimeImmutable('+1 second');
                        return new RateLimit($this->accepted ? 10 : 0, $retryAfter, $this->accepted, 10);
                    }
                    public function reserve(int $tokens = 1, ?float $maxTime = null): \Symfony\Component\RateLimiter\Reservation
                    {
                        throw new \LogicException('reserve not used');
                    }
                    public function reset(): void {}
                };
            }
        };
    }

    private function makeSecurity(?UserInterface $user): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        return $security;
    }

    private function makeProcessor(
        PipelineRunner $runner,
        EntityManagerInterface $em,
        FilesystemOperator $storage,
        RateLimiterFactoryInterface $limiterFactory,
        Security $security,
    ): PreviewRequestProcessor {
        return new PreviewRequestProcessor(
            $runner,
            $em,
            $storage,
            $limiterFactory,
            $security,
            Validation::createValidator(),
        );
    }

    private function postOperation(): Post
    {
        return new Post(uriTemplate: '/asset_transformations/preview');
    }

    private function makeRequest(int $assetId, string $ext = 'png', array $steps = [['type' => 'resize', 'params' => ['width' => 256]]]): PreviewRequest
    {
        $r = new PreviewRequest();
        $r->assetId = $assetId;
        $r->ext = $ext;
        $r->steps = $steps;
        return $r;
    }

    public function testPostReturns200WithBinaryAndNoStore(): void
    {
        $asset = $this->makePublicAsset(1);
        $proc = $this->makeProcessor(
            $this->makeRunner('PNGBYTES'),
            $this->makeEm([1 => $asset]),
            $this->makeStorage('original'),
            $this->makeLimiterFactory(accepted: true),
            $this->makeSecurity(new InMemoryUser('alice', null, ['ROLE_USER'])),
        );

        $response = $proc->process($this->makeRequest(1), $this->postOperation());

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PNGBYTES', $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testPostUnauthenticatedReturns401(): void
    {
        $proc = $this->makeProcessor(
            $this->makeRunner('PNGBYTES'),
            $this->makeEm([1 => $this->makePublicAsset(1)]),
            $this->makeStorage('original'),
            $this->makeLimiterFactory(accepted: true),
            $this->makeSecurity(null), // no user
        );

        $response = $proc->process($this->makeRequest(1), $this->postOperation());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testNonPublicAssetReturns404(): void
    {
        // T-05-03 / WARNING #3 — Asset !isPublic MUST be answered with 404,
        // aligned with the public route /t/* (never 403, never internal details).
        $proc = $this->makeProcessor(
            $this->makeRunner('PNGBYTES'),
            $this->makeEm([2 => $this->makePrivateAsset(2)]),
            $this->makeStorage('original'),
            $this->makeLimiterFactory(accepted: true),
            $this->makeSecurity(new InMemoryUser('alice', null, ['ROLE_USER'])),
        );

        $response = $proc->process($this->makeRequest(2), $this->postOperation());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testMissingAssetReturns404(): void
    {
        $proc = $this->makeProcessor(
            $this->makeRunner('PNGBYTES'),
            $this->makeEm([]), // empty repo
            $this->makeStorage('original'),
            $this->makeLimiterFactory(accepted: true),
            $this->makeSecurity(new InMemoryUser('alice', null, ['ROLE_USER'])),
        );

        $response = $proc->process($this->makeRequest(9999), $this->postOperation());

        self::assertSame(404, $response->getStatusCode());
    }

    public function testInvalidExtReturns422(): void
    {
        $proc = $this->makeProcessor(
            $this->makeRunner('PNGBYTES'),
            $this->makeEm([1 => $this->makePublicAsset(1)]),
            $this->makeStorage('original'),
            $this->makeLimiterFactory(accepted: true),
            $this->makeSecurity(new InMemoryUser('alice', null, ['ROLE_USER'])),
        );

        $req = $this->makeRequest(1, 'gif'); // not in allowlist
        $response = $proc->process($req, $this->postOperation());

        self::assertSame(422, $response->getStatusCode());
    }
}
