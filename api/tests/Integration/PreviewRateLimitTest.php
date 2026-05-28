<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ApiResource\PreviewRequest;
use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Service\AssetTransformation\PipelineResult;
use App\Service\AssetTransformation\PipelineRunner;
use App\State\PreviewRequestProcessor;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Validator\Validation;

/**
 * EDITOR-04 / T-05-01 — the preview endpoint MUST drop the 11th request in a
 * 60-second window to a value-429 response carrying a Retry-After header.
 *
 * The limiter is a per-user token bucket (10 / minute). This test asserts the
 * processor's behaviour when the underlying Limit::isAccepted() returns false:
 * the response MUST be 429 and MUST carry Retry-After (seconds, >= 1).
 */
final class PreviewRateLimitTest extends TestCase
{
    private function makeRunner(): PipelineRunner
    {
        return new class extends PipelineRunner {
            public function __construct() {}
            public function run(AssetTransformation $tx, string $bytes, string $outputExt, bool $bypassCache = false): PipelineResult
            {
                return new PipelineResult('PNG', 'image/png', 1, [], ['resize']);
            }
        };
    }

    private function makeAsset(): Asset
    {
        $asset = new Asset();
        $rc = new \ReflectionClass($asset);
        $prop = $rc->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($asset, 1);
        $asset->setIsPublic(true);
        $asset->setS3Key('0/1.png');
        return $asset;
    }

    private function makeEm(Asset $asset): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($asset);
        return $em;
    }

    private function makeStorage(): FilesystemOperator
    {
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('read')->willReturn('original');
        $fs->method('fileExists')->willReturn(true);
        return $fs;
    }

    /**
     * Sequencer LimiterFactory: returns a limiter whose consume() is accepted
     * the first {@param $acceptedHits} times, refused thereafter.
     */
    private function makeSequencedLimiterFactory(int $acceptedHits): RateLimiterFactoryInterface
    {
        return new class($acceptedHits) implements RateLimiterFactoryInterface {
            public int $callCount = 0;
            public function __construct(private readonly int $acceptedHits) {}
            public function create(?string $key = null): LimiterInterface
            {
                $parent = $this;
                return new class($parent) implements LimiterInterface {
                    public function __construct(private $parent) {}
                    public function consume(int $tokens = 1): RateLimit
                    {
                        $this->parent->callCount++;
                        $accepted = $this->parent->callCount <= $this->parent->acceptedHits;
                        $retryAfter = new \DateTimeImmutable('+30 seconds');
                        return new RateLimit($accepted ? 10 : 0, $retryAfter, $accepted, 10);
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

    private function makeProc(RateLimiterFactoryInterface $limiterFactory): PreviewRequestProcessor
    {
        return new PreviewRequestProcessor(
            $this->makeRunner(),
            $this->makeEm($this->makeAsset()),
            $this->makeStorage(),
            $limiterFactory,
            (function (): Security {
                $s = $this->createMock(Security::class);
                $s->method('getUser')->willReturn(new InMemoryUser('alice', null, ['ROLE_USER']));
                return $s;
            })(),
            Validation::createValidator(),
        );
    }

    private function makeRequest(): PreviewRequest
    {
        $r = new PreviewRequest();
        $r->assetId = 1;
        $r->ext = 'png';
        $r->steps = [['type' => 'resize', 'params' => ['width' => 256]]];
        return $r;
    }

    public function testEleventhRequestReturns429WithRetryAfter(): void
    {
        $factory = $this->makeSequencedLimiterFactory(acceptedHits: 10);
        $proc = $this->makeProc($factory);
        $op = new Post(uriTemplate: '/asset_transformations/preview');

        // First 10 must pass (200).
        for ($i = 1; $i <= 10; $i++) {
            $r = $proc->process($this->makeRequest(), $op);
            self::assertSame(200, $r->getStatusCode(), sprintf('Request #%d should be 200', $i));
        }

        // 11th must be throttled.
        $response = $proc->process($this->makeRequest(), $op);
        self::assertSame(429, $response->getStatusCode());
        $retryAfter = $response->headers->get('Retry-After');
        self::assertNotNull($retryAfter, 'Retry-After header must be set on 429.');
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
    }
}
