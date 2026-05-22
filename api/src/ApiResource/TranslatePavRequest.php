<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\State\TranslatePavProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/translate_pavs',
            processor: TranslatePavProcessor::class,
            normalizationContext: ['groups' => ['translate_pav:read']],
            denormalizationContext: ['groups' => ['translate_pav:write']]
        ),
    ]
)]
#[MenuGroup('hidden')]
class TranslatePavRequest
{
    /** IRI of the source ProductAttributeValue (e.g. "/api/product_attribute_values/42"). */
    #[Groups(['translate_pav:write'])]
    public ?string $sourceAttributeValue = null;

    /** Numeric id alternative to the IRI above. */
    #[Groups(['translate_pav:write'])]
    public ?int $sourceAttributeValueId = null;

    /** If true, overwrite existing PAVs in target locales. Defaults to false (only fill gaps). */
    #[Groups(['translate_pav:write'])]
    public bool $overwriteExisting = false;

    #[Groups(['translate_pav:read'])]
    public int $createdCount = 0;

    #[Groups(['translate_pav:read'])]
    public int $skippedCount = 0;

    /** List of locale codes that were created (translated). */
    #[Groups(['translate_pav:read'])]
    public array $createdLocales = [];

    /** List of locale codes that already had a value and were skipped. */
    #[Groups(['translate_pav:read'])]
    public array $skippedLocales = [];
}
