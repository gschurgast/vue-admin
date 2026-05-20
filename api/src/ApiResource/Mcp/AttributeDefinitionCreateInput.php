<?php

namespace App\ApiResource\Mcp;

class AttributeDefinitionCreateInput
{
    /**
     * @param array<string,mixed>|null $validationRules e.g. {"min":10,"max":500} or {"minLength":1,"maxLength":100,"pattern":"^[A-Z]"}
     * @param array<int,mixed>|null    $allowedValues   Enum-style enumeration of allowed raw values
     */
    public function __construct(
        public string $code,
        public string $type,
        public bool $isLocalizable = false,
        public bool $isScopable = false,
        public bool $isRequired = false,
        public ?string $defaultValue = null,
        public ?string $helpText = null,
        public ?string $unit = null,
        public ?string $relationEndpoint = null,
        public ?array $validationRules = null,
        public ?array $allowedValues = null,
        public int $sortOrder = 0,
    ) {}
}