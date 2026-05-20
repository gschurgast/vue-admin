<?php

namespace App\ApiResource\Mcp;

class AttributeOptionCreateInput
{
    public function __construct(
        public int $attributeDefinitionId,
        public string $code,
        public int $sortOrder = 0,
    ) {}
}