<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\State\GenerateProductContentProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/products/{productId}/generate-content',
            processor: GenerateProductContentProcessor::class,
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            outputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']]
        )
    ]
)]
#[MenuGroup('hidden')]
class GenerateProductContentRequest
{
    #[ApiProperty(identifier: true)]
    public ?int $productId = null;

    public ?string $locale = 'fr_FR';

    public ?string $generatedContent = null;

    public ?string $attributeValueId = null;
}
