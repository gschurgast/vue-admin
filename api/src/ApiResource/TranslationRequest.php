<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\TranslationRequestProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/translate',
            processor: TranslationRequestProcessor::class,
            normalizationContext: ['groups' => ['translation:read']],
            denormalizationContext: ['groups' => ['translation:write']]
        )
    ]
)]
class TranslationRequest
{
    #[Groups(['translation:write'])]
    public ?string $text = null;

    #[Groups(['translation:write'])]
    public ?string $sourceLocale = null;

    #[Groups(['translation:write'])]
    public ?string $targetLocale = null;

    #[Groups(['translation:read'])]
    public ?string $translation = null;
}
