<?php

namespace App\Agent\Tool;

use App\Agent\Mcp\McpInProcessClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'list_attribute_definitions',
    description: 'List attribute definitions available in the catalog. Filter by code (partial), type (text, number, boolean, enum, ...) or isRequired.'
)]
final class ListAttributeDefinitionsTool
{
    public function __construct(private readonly McpInProcessClient $mcp) {}

    public function __invoke(?string $code = null, ?string $type = null, ?bool $isRequired = null): string
    {
        return $this->mcp->callAsJson('list_attribute_definitions', array_filter([
            'code' => $code,
            'type' => $type,
            'isRequired' => $isRequired,
        ], static fn ($v) => $v !== null));
    }
}