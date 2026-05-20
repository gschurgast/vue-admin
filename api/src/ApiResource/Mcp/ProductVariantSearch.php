<?php

namespace App\ApiResource\Mcp;

class ProductVariantSearch
{
    public function __construct(
        public ?int $product = null,
        public ?string $sku = null,
        public ?string $ean = null,
        public ?int $page = 1,
        public ?int $itemsPerPage = 30,
    ) {}
}
