<?php

namespace App\ApiResource\Mcp;

class AttributeDefinitionSearch
{
    public function __construct(
        public ?string $code = null,
        public ?string $type = null,
        public ?bool $isRequired = null,
        public ?int $page = 1,
        public ?int $itemsPerPage = 50,
    ) {}
}
