<?php

namespace App\Entity\Attribute;

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
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'option' => 'exact', 'locale' => 'exact', 'label' => 'partial'])]
#[ORM\Table(
    name: 'attribute_option_translation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_option_locale',
            columns: ['option_id', 'locale']
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
    normalizationContext: ['groups' => ['option:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['option:write']]
)]
#[MenuGroup('hidden')]
class AttributeOptionTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['option:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttributeOption::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['option:read', 'option:write'])]
    #[MaxDepth(1)]
    #[ApiProperty(fetchEager: false)]
    private ?AttributeOption $option = null;

    #[ORM\Column(length: 10, enumType: Locale::class)]
    #[Groups(['option:read', 'option:write'])]
    private Locale $locale;

    #[ORM\Column(length: 255)]
    #[Groups(['option:read', 'option:write'])]
    private string $label;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOption(): ?AttributeOption
    {
        return $this->option;
    }

    public function setOption(?AttributeOption $option): self
    {
        $this->option = $option;

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
