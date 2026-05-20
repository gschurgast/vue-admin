<?php

namespace App\ApiResource\Mcp;

class AttributeOptionSearch
{
    public function __construct(
        public ?int $attributeDefinition = null,
        public ?string $code = null,
        public ?int $page = 1,
        public ?int $itemsPerPage = 50,
    ) {}
}