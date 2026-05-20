<?php

namespace App\ApiResource\Mcp;

class ProductAttributeValueSearch
{
    public function __construct(
        public ?int $product = null,
        public ?int $variant = null,
        public ?int $attributeDefinition = null,
        public ?string $locale = null,
        public ?int $page = 1,
        public ?int $itemsPerPage = 50,
    ) {}
}
