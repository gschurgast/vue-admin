<?php

namespace App\Entity\Attribute;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator as AppAssert;

#[ORM\Entity]
#[ORM\Table(
    name: 'attribute_option',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_attribute_code',
            columns: ['attribute_definition_id', 'code']
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
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'code' => 'ipartial', 'attribute' => 'exact'])]
#[MenuGroup('Settings')]
class AttributeOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['option:read', 'value:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AttributeDefinition::class)]
    #[ORM\JoinColumn(nullable: false, name: 'attribute_definition_id')]
    #[Groups(['option:read', 'option:write'])]
    private ?AttributeDefinition $attribute = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[AppAssert\Code]
    #[Groups(['option:read', 'option:write', 'value:read'])]
    private string $code;

    #[ORM\Column(type: 'integer')]
    #[Groups(['option:read', 'option:write'])]
    private int $sortOrder = 0;

    /**
     * @var Collection<int, AttributeOptionTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'option',
        targetEntity: AttributeOptionTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Groups(['option:read', 'option:write'])]
    #[MaxDepth(1)]
    private Collection $translations;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttribute(): ?AttributeDefinition
    {
        return $this->attribute;
    }

    public function setAttribute(AttributeDefinition $attribute): self
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * @return Collection<int, AttributeOptionTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(AttributeOptionTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setOption($this);
        }

        return $this;
    }

    public function removeTranslation(AttributeOptionTranslation $translation): self
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getOption() === $this) {
                $translation->setOption(null);
            }
        }

        return $this;
    }
}
