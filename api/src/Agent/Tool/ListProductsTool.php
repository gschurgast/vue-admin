<?php

namespace App\Agent\Tool;

use App\Agent\Mcp\McpInProcessClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(name: 'list_products', description: 'List products from the catalog. Optional filters: skuRoot (partial), isActive.')]
final class ListProductsTool
{
    public function __construct(private readonly McpInProcessClient $mcp) {}

    public function __invoke(?string $skuRoot = null, ?bool $isActive = null): string
    {
        return $this->mcp->callAsJson('list_products', array_filter([
            'skuRoot' => $skuRoot,
            'isActive' => $isActive,
        ], static fn ($v) => $v !== null));
    }
}
