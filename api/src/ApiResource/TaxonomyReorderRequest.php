<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\State\TaxonomyReorderProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'TaxonomyReorder',
    operations: [
        new Post(
            uriTemplate: '/taxonomies/reorder',
            processor: TaxonomyReorderProcessor::class,
            normalizationContext: ['groups' => ['taxonomy_reorder:read']],
            denormalizationContext: ['groups' => ['taxonomy_reorder:write']],
            security: "is_granted('ROLE_USER')"
        )
    ]
)]
#[MenuGroup('hidden')]
class TaxonomyReorderRequest
{
    /**
     * @var array<int, array{id:int, parent?:?string, position:int}>
     */
    #[Groups(['taxonomy_reorder:read', 'taxonomy_reorder:write'])]
    public array $items = [];

    #[Groups(['taxonomy_reorder:read'])]
    public int $updated = 0;
}
