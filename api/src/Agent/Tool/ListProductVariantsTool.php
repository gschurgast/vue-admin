<?php

namespace App\Agent\Tool;

use App\Agent\Mcp\McpInProcessClient;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(name: 'list_product_variants', description: 'List product variants. Filter by parent productId, sku (partial) or ean.')]
final class ListProductVariantsTool
{
    public function __construct(private readonly McpInProcessClient $mcp) {}

    public function __invoke(?int $product = null, ?string $sku = null, ?string $ean = null): string
    {
        return $this->mcp->callAsJson('list_product_variants', array_filter([
            'product' => $product,
            'sku' => $sku,
            'ean' => $ean,
        ], static fn ($v) => $v !== null));
    }
}