<?php

namespace App\Entity\Asset;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\MenuGroup;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;

/**
 * Undirected similarity edge between two assets.
 *
 * Enforces (assetA.id < assetB.id) so (1↔2) and (2↔1) cannot coexist; this is
 * also guaranteed by a CHECK constraint on the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'asset_similarity')]
#[ApiFilter(SearchFilter::class, properties: ['assetA' => 'exact', 'assetB' => 'exact'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    normalizationContext: ['groups' => ['asset_similarity:read'], 'enable_max_depth' => true],
)]
#[MenuGroup('hidden')]
class AssetSimilarity
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(name: 'asset_a_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['asset_similarity:read'])]
    #[MaxDepth(1)]
    private Asset $assetA;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(name: 'asset_b_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['asset_similarity:read'])]
    #[MaxDepth(1)]
    private Asset $assetB;

    /**
     * Cosine similarity in [0, 1] (vectors are L2-normalised before being stored).
     */
    #[ORM\Column(type: 'float')]
    #[Groups(['asset_similarity:read'])]
    private float $score;

    public function __construct(Asset $a, Asset $b, float $score)
    {
        // Canonicalise to (smaller id, larger id) so the pair is unique.
        if ($a->getId() === null || $b->getId() === null) {
            throw new \LogicException('Both assets must be persisted before linking them.');
        }
        if ($a->getId() === $b->getId()) {
            throw new \LogicException('Cannot link an asset to itself.');
        }
        if ($a->getId() < $b->getId()) {
            $this->assetA = $a;
            $this->assetB = $b;
        } else {
            $this->assetA = $b;
            $this->assetB = $a;
        }
        $this->score = $score;
    }

    public function getAssetA(): Asset
    {
        return $this->assetA;
    }

    public function getAssetB(): Asset
    {
        return $this->assetB;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function setScore(float $score): static
    {
        $this->score = $score;
        return $this;
    }
}
