<?php

namespace App\Entity\Taxonomy;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\Enum\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;

#[ORM\Entity]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'taxonomy' => 'exact', 'locale' => 'exact', 'label' => 'partial'])]
#[ORM\Table(
    name: 'taxonomy_translation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_taxonomy_locale',
            columns: ['taxonomy_id', 'locale']
        )
    ]
)]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(),
        new Patch(),
        new Delete()
    ],
    normalizationContext: ['groups' => ['taxonomy:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['taxonomy:write']]
)]
#[MenuGroup('hidden')]
class TaxonomyTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['taxonomy:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Taxonomy::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    #[MaxDepth(1)]
    #[ApiProperty(fetchEager: false)]
    private ?Taxonomy $taxonomy = null;

    #[ORM\Column(length: 10, enumType: Locale::class)]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    private Locale $locale;

    #[ORM\Column(length: 255)]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    private string $label;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTaxonomy(): ?Taxonomy
    {
        return $this->taxonomy;
    }

    public function setTaxonomy(?Taxonomy $taxonomy): self
    {
        $this->taxonomy = $taxonomy;

        return $this;
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function setLocale(Locale $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }
}