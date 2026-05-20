<?php

namespace App\ApiResource\Mcp;

class ProductVariantCreateInput
{
    public function __construct(
        public int $productId,
        public string $sku,
        public ?string $ean = null,
        public bool $isDefault = false,
    ) {}
}