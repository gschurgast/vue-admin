<?php

namespace App\Entity\Collection;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Mcp\CollectionSearch;
use App\ApiResource\Mcp\CollectionUpdateInput;
use App\ApiResource\Mcp\IdentifierInput;
use App\Attribute\MenuGroup;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator as AppAssert;

#[ORM\Entity]
#[ORM\Table(
    name: 'collection',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_collection_code',
            columns: ['code']
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
    mcp: [
        'list_collections' => new McpToolCollection(
            description: 'List product collections. Filter by code (partial match).',
            input: CollectionSearch::class,
            provider: CollectionProvider::class,
        ),
        'get_collection' => new McpTool(
            description: 'Get a single collection by id, including its translations.',
            uriTemplate: '/collections/{id}',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
            input: IdentifierInput::class,
            provider: ItemProvider::class,
        ),
        'create_collection' => new McpTool(
            description: 'Create a new collection with a unique code. Translations must be added separately.',
            processor: \App\State\Mcp\GenericCreateProcessor::class,
        ),
        'update_collection' => new McpTool(
            description: 'Update fields of an existing collection. Only the code can be changed here.',
            input: CollectionUpdateInput::class,
            processor: \App\State\Mcp\GenericUpdateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['collection:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['collection:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'code' => 'ipartial'])]
#[MenuGroup('Product')]
class Collection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['collection:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[AppAssert\Code]
    #[Groups(['collection:read', 'collection:write'])]
    private string $code;

    /**
     * @var DoctrineCollection<int, CollectionTranslation>
     */
    #[ORM\OneToMany(
        mappedBy: 'collection',
        targetEntity: CollectionTranslation::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Groups(['collection:read', 'collection:write'])]
    #[MaxDepth(1)]
    private DoctrineCollection $translations;

    public function __construct()
    {
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

    /**
     * @return DoctrineCollection<int, CollectionTranslation>
     */
    public function getTranslations(): DoctrineCollection
    {
        return $this->translations;
    }

    public function addTranslation(CollectionTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setCollection($this);
        }

        return $this;
    }

    public function removeTranslation(CollectionTranslation $translation): self
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getCollection() === $this) {
                $translation->setCollection(null);
            }
        }

        return $this;
    }
}
