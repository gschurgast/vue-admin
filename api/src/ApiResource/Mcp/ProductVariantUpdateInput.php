<?php

namespace App\ApiResource\Mcp;

class ProductVariantUpdateInput
{
    public function __construct(
        public int $id,
        public ?string $sku = null,
        public ?string $ean = null,
        public ?bool $isDefault = null,
    ) {}
}