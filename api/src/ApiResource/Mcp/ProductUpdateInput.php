<?php

namespace App\ApiResource\Mcp;

class ProductUpdateInput
{
    public function __construct(
        public int $id,
        public ?string $skuRoot = null,
        public ?bool $isActive = null,
    ) {}
}