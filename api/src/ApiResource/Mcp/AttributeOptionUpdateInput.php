<?php

namespace App\ApiResource\Mcp;

class AttributeOptionUpdateInput
{
    public function __construct(
        public int $id,
        public ?string $code = null,
        public ?int $sortOrder = null,
    ) {}
}