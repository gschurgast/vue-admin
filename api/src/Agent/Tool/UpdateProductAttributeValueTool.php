<?php

namespace App\Agent\Tool;

use App\Agent\Mcp\McpInProcessClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'update_product_attribute_value',
    description: 'Update an existing attribute value (text/number/measure/json/...). Provide the value as a string. For "measure" type, encode as JSON string: {"value":180,"unit":"cm"}. Server-side validation rules from the attribute definition apply.'
)]
final class UpdateProductAttributeValueTool
{
    public function __construct(private readonly McpInProcessClient $mcp) {}

    public function __invoke(int $id, ?string $value = null): string
    {
        return $this->mcp->callAsJson('update_product_attribute_value', array_filter([
            'id' => $id,
            'value' => $value,
        ], static fn ($v) => $v !== null));
    }
}
