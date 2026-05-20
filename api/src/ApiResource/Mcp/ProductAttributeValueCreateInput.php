<?php

namespace App\ApiResource\Mcp;

class ProductAttributeValueCreateInput
{
    /**
     * @param string[]|null $values List of attribute_option ids for multienum types
     */
    public function __construct(
        public int $productId,
        public int $attributeDefinitionId,
        public ?int $variantId = null,
        public ?int $optionId = null,
        public ?string $value = null,
        public ?array $values = null,
        public ?string $locale = null,
        public ?string $market = null,
    ) {}
}