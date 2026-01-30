<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\State\GenerateVariantContentProcessor;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/product_variants/{variantId}/generate-content',
            processor: GenerateVariantContentProcessor::class,
            inputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']],
            outputFormats: ['json' => ['application/json'], 'jsonld' => ['application/ld+json']]
        )
    ]
)]
#[MenuGroup('hidden')]
class GenerateVariantContentRequest
{
    #[ApiProperty(identifier: true)]
    public ?int $variantId = null;

    public ?string $locale = 'fr_FR';

    public ?string $generatedContent = null;

    public ?string $attributeValueId = null;
}
