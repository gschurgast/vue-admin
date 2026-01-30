<?php

namespace App\Entity\Collection;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\Enum\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;

#[ORM\Entity]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'collection' => 'exact', 'locale' => 'exact', 'label' => 'partial'])]
#[ORM\Table(
    name: 'collection_translation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_collection_locale',
            columns: ['collection_id', 'locale']
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
    normalizationContext: ['groups' => ['collection:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['collection:write']]
)]
#[MenuGroup('hidden')]
class CollectionTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['collection:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Collection::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['collection:read', 'collection:write'])]
    #[MaxDepth(1)]
    private ?Collection $collection = null;

    #[ORM\Column(length: 10, enumType: Locale::class)]
    #[Groups(['collection:read', 'collection:write'])]
    private Locale $locale;

    #[ORM\Column(length: 255)]
    #[Groups(['collection:read', 'collection:write'])]
    private string $label;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollection(): ?Collection
    {
        return $this->collection;
    }

    public function setCollection(?Collection $collection): self
    {
        $this->collection = $collection;

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
