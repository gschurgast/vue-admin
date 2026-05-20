<?php

namespace App\ApiResource\Mcp;

class CollectionSearch
{
    public function __construct(
        public ?string $code = null,
        public ?int $page = 1,
        public ?int $itemsPerPage = 30,
    ) {}
}