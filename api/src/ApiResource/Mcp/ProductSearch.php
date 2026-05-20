<?php

namespace App\ApiResource\Mcp;

class ProductSearch
{
    public function __construct(
        public ?string $skuRoot = null,
        public ?bool $isActive = null,
        public ?int $page = 1,
        public ?int $itemsPerPage = 30,
    ) {}
}