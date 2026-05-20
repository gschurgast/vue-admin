<?php

namespace App\ApiResource\Mcp;

class AttributeDefinitionUpdateInput
{
    /**
     * @param array<string,mixed>|null $validationRules
     * @param array<int,mixed>|null    $allowedValues
     */
    public function __construct(
        public int $id,
        public ?string $code = null,
        public ?string $type = null,
        public ?bool $isLocalizable = null,
        public ?bool $isScopable = null,
        public ?bool $isRequired = null,
        public ?string $defaultValue = null,
        public ?string $helpText = null,
        public ?string $unit = null,
        public ?string $relationEndpoint = null,
        public ?array $validationRules = null,
        public ?array $allowedValues = null,
        public ?int $sortOrder = null,
    ) {}
}