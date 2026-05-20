<?php

namespace App\ApiResource\Mcp;

class IdentifierInput
{
    public function __construct(
        public int $id,
    ) {}
}