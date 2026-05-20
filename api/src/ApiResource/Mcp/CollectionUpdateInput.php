<?php

namespace App\ApiResource\Mcp;

class CollectionUpdateInput
{
    public function __construct(
        public int $id,
        public ?string $code = null,
    ) {}
}