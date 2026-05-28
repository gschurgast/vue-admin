<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PreviewRequest;
use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\TransformationPipelineException;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Phase 5 / Plan 05-01 — POST /api/asset_transformations/preview processor.
 *
 * Builds an ephemeral (non-persisted) {@see AssetTransformation} from the inline
 * payload, runs {@see PipelineRunner::run()} with `bypassCache: true`, and returns
 * the rendered binary directly via a {@see Response} short-circuit (API Platform
 * lets processors return Response when the operation has no output mapping).
 *
 * Invariants:
 *  - JWT ROLE_USER required (D-11) — guard duplicated server-side defense-in-depth.
 *  - Rate-limited 10 req/min/user via token bucket (D-10, T-05-01).
 *  - Target asset MUST be `isPublic === true`, else 404 STRICT (T-05-03, /t/* aligned).
 *    PAS de check ownership ce phase ; out-of-scope, tracker en STATE.md.
 *  - Output ext MUST be in {@see PreviewRequest::ALLOWED_EXTS} (T-05-07).
 *  - Response: image/{ext}, Cache-Control: no-store, X-Robots-Tag: noindex (D-09).
 *  - Bytes are NEVER written to S3 nor protected by the Redis generation lock.
 */
final class PreviewRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly FilesystemOperator $assetsStorage,
        private readonly PipelineRunner $runner,
        #[Autowire(service: 'limiter.preview_endpoint')]
        private readonly RateLimiterFactoryInterface $previewEndpointLimiter,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param mixed $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        if (!$operation instanceof Post) {
            throw new BadRequestHttpException('Operation not supported.');
        }
        if (!$data instanceof PreviewRequest) {
            throw new BadRequestHttpException('Expected PreviewRequest payload.');
        }

        $user = $this->security->getUser();
        if ($user === null) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        $limit = $this->previewEndpointLimiter->create($user->getUserIdentifier())->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time())));
            throw new TooManyRequestsHttpException($retryAfter, 'Preview rate limit exceeded.');
        }

        $ext = strtolower(ltrim($data->ext, '.'));
        if (!in_array($ext, PreviewRequest::ALLOWED_EXTS, true)) {
            throw new BadRequestHttpException(sprintf('ext must be one of: %s.', implode(', ', PreviewRequest::ALLOWED_EXTS)));
        }

        $asset = $this->em->getRepository(Asset::class)->find($data->assetId);
        if ($asset === null || !$asset->isPublic()) {
            throw new NotFoundHttpException();
        }

        $s3Key = (string) $asset->getS3Key();
        if ($s3Key === '') {
            throw new NotFoundHttpException();
        }
        try {
            $originalBytes = $this->assetsStorage->read($s3Key);
        } catch (FilesystemException $e) {
            $this->logger->warning('preview: asset binary missing', ['assetId' => $asset->getId(), 'error' => $e->getMessage()]);
            throw new NotFoundHttpException();
        }

        $tx = new AssetTransformation();
        $tx->setCode('__preview__');
        $tx->setLabel('Preview');
        $tx->setVersionHash(str_repeat('0', 40));

        $position = 0;
        foreach ($data->steps as $idx => $rawStep) {
            if (!is_array($rawStep) || !isset($rawStep['type']) || !is_string($rawStep['type'])) {
                throw new BadRequestHttpException(sprintf('steps[%d].type is required.', $idx));
            }
            $stepType = StepType::tryFrom($rawStep['type']);
            if ($stepType === null) {
                throw new BadRequestHttpException(sprintf('steps[%d].type "%s" is not a valid step type.', $idx, $rawStep['type']));
            }
            $params = $rawStep['params'] ?? [];
            if (!is_array($params)) {
                throw new BadRequestHttpException(sprintf('steps[%d].params must be an object.', $idx));
            }

            $step = new TransformationStep();
            $step->setTransformation($tx);
            $step->setType($stepType);
            $step->setParams($params);
            $step->setPosition($position++);
            $tx->addStep($step);
        }

        try {
            $result = $this->runner->run($tx, $originalBytes, $ext, bypassCache: true);
        } catch (TransformationPipelineException $e) {
            $this->logger->info('preview: pipeline failure', ['error' => $e->getMessage()]);
            throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'Preview pipeline failed: '.$e->getMessage());
        }

        $response = new Response($result->bytes, Response::HTTP_OK);
        $response->headers->set('Content-Type', $result->contentType);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Preview-Total-Ms', (string) $result->totalMs);
        if ($result->warnings !== []) {
            $response->headers->set('X-Preview-Warnings', (string) json_encode($result->warnings));
        }

        return $response;
    }
}