<?php

namespace App\Agent\Tool;

use App\Agent\Mcp\McpInProcessClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(name: 'get_product', description: 'Get a single product by id, including its variants and attribute values.')]
final class GetProductTool
{
    public function __construct(private readonly McpInProcessClient $mcp) {}

    public function __invoke(int $id): string
    {
        return $this->mcp->callAsJson('get_product', ['id' => $id]);
    }
}