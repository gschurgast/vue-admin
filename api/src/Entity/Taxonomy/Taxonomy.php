<?php

namespace App\Entity\Taxonomy;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\Validator as AppAssert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(
    name: 'taxonomy',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_taxonomy_code',
            columns: ['code']
        )
    ]
)]
#[ApiResource(
    shortName: 'Taxonomy',
    types: ['Taxonomy'],
    operations: [
        new Get(uriTemplate: '/taxonomies/{id}'),
        new GetCollection(uriTemplate: '/taxonomies'),
        new Post(uriTemplate: '/taxonomies'),
        new Patch(uriTemplate: '/taxonomies/{id}'),
        new Delete(uriTemplate: '/taxonomies/{id}')
    ],
    order: ['position' => 'ASC', 'code' => 'ASC'],
    normalizationContext: ['groups' => ['taxonomy:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['taxonomy:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'code' => 'ipartial', 'parent' => 'exact'])]
#[MenuGroup('Product')]
class Taxonomy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['taxonomy:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[AppAssert\Code]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    private string $code;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    #[MaxDepth(1)]
    private ?Taxonomy $parent = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    private int $position = 0;

    /**
     * @var DoctrineCollection<int, Taxonomy>
     */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['position' => 'ASC', 'code' => 'ASC'])]
    #[Groups(['taxonomy:read'])]
    #[MaxDepth(1)]
    private DoctrineCollection $children;

    /**
     * @var DoctrineCollection<int, TaxonomyTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'taxonomy',
        targetEntity: TaxonomyTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Groups(['taxonomy:read', 'taxonomy:write'])]
    #[MaxDepth(1)]
    private DoctrineCollection $translations;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getParent(): ?Taxonomy
    {
        return $this->parent;
    }

    public function setParent(?Taxonomy $parent): self
    {
        $this->parent = $parent;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return DoctrineCollection<int, Taxonomy>
     */
    public function getChildren(): DoctrineCollection
    {
        return $this->children;
    }

    public function addChild(Taxonomy $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(Taxonomy $child): self
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    /**
     * @return DoctrineCollection<int, TaxonomyTranslation>
     */
    public function getTranslations(): DoctrineCollection
    {
        return $this->translations;
    }

    public function addTranslation(TaxonomyTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setTaxonomy($this);
        }

        return $this;
    }

    public function removeTranslation(TaxonomyTranslation $translation): self
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getTaxonomy() === $this) {
                $translation->setTaxonomy(null);
            }
        }

        return $this;
    }
}