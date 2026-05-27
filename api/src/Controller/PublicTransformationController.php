<?php

namespace App\Controller;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\TransformationLookup;
use App\Service\AssetTransformation\TransformationPipelineException;
use App\Service\AssetTransformation\TransformationStorageKey;
use App\Service\AssetTransformation\VariantCache;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\LockFactory;

/**
 * Public route `GET /t/{code}/{id}.{ext}` (Plan 03-03).
 *
 * Workflow per request (D-04, D-10, D-19, D-20, D-21):
 *
 * 1. Feature flag OFF → 404 immediate (no DB, no DI).
 * 2. Lookup → 404 on every reject (unknown code, missing asset, !isPublic,
 *    AI step). Never 403 (T-03-11).
 * 3. ETag short-circuit: If-None-Match matches the deterministic ETag → 304.
 * 4. Cache hit: stream variant from S3, immutable headers.
 * 5. Cache miss → acquire Redis lock `lock:tx:{storageKey}` (TTL 10s).
 *    - acquire fails → waiter loop ≤5s re-checking S3, then 503 + Retry-After.
 *    - acquire succeeds → re-check cache (another worker may have just
 *      finished), generate via PipelineRunner, write to S3, release lock
 *      BEFORE streaming. Lock covers GENERATION, not streaming.
 *
 * Errors are typed via TransformationPipelineException::CODE_* and mapped to
 * 503 (cap exceeded), 502 (embedder), or 404 (unsupported step). 5xx never
 * leak internals.
 */
#[AsController]
final class PublicTransformationController
{
    public function __construct(
        #[Autowire(param: 'transformations.public_route.enabled')]
        private readonly bool $enabled,
        private readonly TransformationLookup $lookup,
        private readonly VariantCache $cache,
        #[Autowire(service: 'assets.storage')]
        private readonly FilesystemOperator $assetsStorage,
        #[Autowire(service: 'lock.transformations.factory')]
        private readonly LockFactory $lockFactory,
        private readonly PipelineRunner $runner,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function serve(string $code, int $id, string $ext, Request $request): Response
    {
        // (1) Feature flag — first instruction, before any DB/DI (D-12, T-03-17).
        if (!$this->enabled) {
            return $this->notFoundShort();
        }

        // (2) Lookup — unified 404 (D-10).
        try {
            [$tx, $asset] = $this->lookup->findOr404($code, $id);
        } catch (NotFoundHttpException) {
            return $this->notFoundShort();
        }

        $extNorm = strtolower($ext);
        $contentType = $this->extToContentType($extNorm);
        $versionHash = (string) $tx->getVersionHash();
        $hash8 = $versionHash === '' ? '00000000' : substr($versionHash, 0, 8);
        $storageKey = TransformationStorageKey::forVariant(
            (int) $tx->getId(),
            $versionHash === '' ? str_repeat('0', 40) : $versionHash,
            (int) $asset->getId(),
            $extNorm,
        );
        $etag = sprintf('"%d-v%s-%d-%s"', $tx->getId(), $hash8, $asset->getId(), $extNorm);

        // (3) If-None-Match short-circuit — no S3 read, no pipeline.
        if ($request->headers->get('If-None-Match') === $etag) {
            return $this->withCommonHeaders(new Response(null, Response::HTTP_NOT_MODIFIED), $etag, $contentType, $tx);
        }

        // (4) Cache hit.
        if ($this->cache->has($storageKey)) {
            return $this->streamFromCache($storageKey, $etag, $contentType, $tx);
        }

        // (5) Cache miss → lock.
        $lock = $this->lockFactory->createLock('lock:tx:'.$storageKey, ttl: 10.0, autoRelease: true);
        if (!$lock->acquire(false)) {
            // Another worker is generating. Wait up to 5s, re-check S3.
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                usleep(250_000);
                if ($this->cache->has($storageKey)) {
                    return $this->streamFromCache($storageKey, $etag, $contentType, $tx);
                }
            }
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '2']);
        }

        $lockReleased = false;
        try {
            // Re-check inside the critical section — another worker may have
            // finished between our miss-check and the lock acquisition.
            // W5: release the lock BEFORE streaming a blob that already exists.
            if ($this->cache->has($storageKey)) {
                $lock->release();
                $lockReleased = true;
                return $this->streamFromCache($storageKey, $etag, $contentType, $tx);
            }

            // Load original asset bytes via Flysystem (works dev FS / prod S3).
            try {
                $s3Key = (string) $asset->getS3Key();
                if ($s3Key === '') {
                    return $this->notFoundShort();
                }
                $originalBytes = $this->assetsStorage->read($s3Key);
            } catch (FilesystemException $e) {
                $this->logger->warning('asset binary missing', ['assetId' => $asset->getId(), 'error' => $e->getMessage()]);
                return $this->notFoundShort();
            }

            try {
                $result = $this->runner->run($tx, $originalBytes, $extNorm);
            } catch (TransformationPipelineException $e) {
                $this->logger->warning('transformation pipeline failed', [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'tx' => $tx->getCode(),
                ]);
                $status = match ($e->getCode()) {
                    TransformationPipelineException::CODE_CAP_EXCEEDED => Response::HTTP_SERVICE_UNAVAILABLE,
                    TransformationPipelineException::CODE_EMBEDDER_ERROR => Response::HTTP_BAD_GATEWAY,
                    TransformationPipelineException::CODE_UNSUPPORTED_STEP => Response::HTTP_NOT_FOUND,
                    default => Response::HTTP_INTERNAL_SERVER_ERROR,
                };
                $headers = $status === Response::HTTP_SERVICE_UNAVAILABLE ? ['Retry-After' => '2'] : [];
                return new Response('', $status, $headers);
            }

            $this->cache->write($storageKey, $result->bytes, $result->contentType);

            // W5: release BEFORE the stream — generation is complete, S3 has the object.
            $lock->release();
            $lockReleased = true;

            return $this->streamFromCache($storageKey, $etag, $result->contentType, $tx);
        } finally {
            // Safety net: any uncaught exception before the explicit release.
            if (!$lockReleased) {
                $lock->release();
            }
        }
    }

    private function streamFromCache(string $key, string $etag, string $contentType, AssetTransformation $tx): StreamedResponse
    {
        $cache = $this->cache;
        $response = new StreamedResponse(function () use ($cache, $key): void {
            $stream = $cache->read($key);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, Response::HTTP_OK);
        return $this->withCommonHeaders($response, $etag, $contentType, $tx);
    }

    private function withCommonHeaders(Response $r, string $etag, string $contentType, AssetTransformation $tx): Response
    {
        $r->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $r->headers->set('ETag', $etag);
        $r->headers->set('Content-Type', $contentType);
        $r->headers->set('Cross-Origin-Resource-Policy', 'cross-origin');

        $warnings = $tx->getWarnings();
        if (!empty($warnings)) {
            $codes = array_map(static fn (array $w): string => (string) ($w['code'] ?? ''), $warnings);
            $codes = array_values(array_filter($codes, static fn (string $c) => $c !== ''));
            if (!empty($codes)) {
                $r->headers->set('X-Transformation-Warnings', implode(', ', $codes));
            }
        }
        return $r;
    }

    /**
     * 404 response shared by every rejection branch (flag off, lookup miss,
     * missing binary, unsupported step). Body intentionally empty, short TTL
     * lets browsers/CDN amortize enumeration scans (T-03-19).
     */
    private function notFoundShort(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'public, max-age=300']);
    }

    private function extToContentType(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
