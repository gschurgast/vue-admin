<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\MenuGroup;
use App\State\ProductAttributeValuesProvider;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/product_attribute_values/by_product/{productId}',
            provider: ProductAttributeValuesProvider::class
        )
    ]
)]
#[MenuGroup('hidden')]
class ProductAttributeValuesRequest
{
    #[ApiProperty(identifier: true)]
    public ?int $productId = null;

    public ?int $variantId = null;

    /** @var array<mixed> */
    public array $productAttributes = [];

    /** @var array<mixed> */
    public array $variantAttributes = [];
}
