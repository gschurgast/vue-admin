<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\State\PreviewRequestProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Phase 5 / Plan 05-01 — POST `/api/asset_transformations/preview` (D-08).
 *
 * Server-authoritative preview of an inline (non-persisted) step pipeline against
 * an existing public asset. Returns the rendered binary directly (Content-Type:
 * image/{ext}, Cache-Control: no-store, X-Robots-Tag: noindex per D-09).
 *
 * Constraints (verbatim from plan must_haves):
 *  - JWT `ROLE_USER` required (D-11)
 *  - Rate-limited 10 req/min/user via token bucket (D-10, T-05-01)
 *  - Asset MUST be `isPublic === true`, else 404 STRICT (T-05-03, aligned with /t/*)
 *  - Output extension MUST be in allowlist `[png, jpg, jpeg, webp, avif]` (T-05-07)
 *  - Bypasses S3 variant cache and Redis generation lock (T-05-04)
 */
#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/asset_transformations/preview',
            processor: PreviewRequestProcessor::class,
            security: "is_granted('ROLE_USER')",
            normalizationContext: ['groups' => ['preview:read']],
            denormalizationContext: ['groups' => ['preview:write']],
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            outputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
        ),
    ],
)]
#[MenuGroup('hidden')]
final class PreviewRequest
{
    public const ALLOWED_EXTS = ['png', 'jpg', 'jpeg', 'webp', 'avif'];

    #[Groups(['preview:write'])]
    #[Assert\NotNull(message: 'assetId is required.')]
    #[Assert\Positive(message: 'assetId must be a positive integer.')]
    public int $assetId = 0;

    #[Groups(['preview:write'])]
    #[Assert\NotBlank(message: 'ext is required.')]
    #[Assert\Choice(choices: self::ALLOWED_EXTS, message: 'ext must be one of: {{ choices }}.')]
    public string $ext = 'png';

    /**
     * Inline non-persisted steps:
     *   [{ "type": "resize", "params": { "width": 256 } }, ...]
     *
     * Each step is validated at runtime by the processor via StepParamsFactory.
     *
     * @var array<int, array{type: string, params?: array<string, mixed>}>
     */
    #[Groups(['preview:write'])]
    #[Assert\NotNull(message: 'steps is required.')]
    #[Assert\Count(min: 1, minMessage: 'At least one step is required.')]
    #[Assert\Type('array')]
    public array $steps = [];
}
