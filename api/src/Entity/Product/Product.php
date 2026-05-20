<?php

namespace App\Entity\Product;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\Link;
use App\Attribute\MenuGroup;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    mcp: [
        'list_products' => new McpToolCollection(
            description: 'List all products with their SKU root and active status. Supports filtering by skuRoot (partial match), isActive, and pagination.',
            input: \App\ApiResource\Mcp\ProductSearch::class,
            provider: CollectionProvider::class,
        ),
        'get_product' => new McpTool(
            description: 'Get a single product by id, including its variants and attribute values.',
            uriTemplate: '/products/{id}',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
            input: \App\ApiResource\Mcp\IdentifierInput::class,
            provider: ItemProvider::class,
        ),
        'create_product' => new McpTool(
            description: 'Create a new product with a unique skuRoot and an active flag.',
            processor: \App\State\Mcp\GenericCreateProcessor::class,
        ),
        'update_product' => new McpTool(
            description: 'Update fields of an existing product. Only provided fields are modified.',
            input: \App\ApiResource\Mcp\ProductUpdateInput::class,
            processor: \App\State\Mcp\GenericUpdateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['product:read']],
    denormalizationContext: ['groups' => ['product:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'skuRoot' => 'ipartial', 'isActive' => 'exact'])]
#[MenuGroup('Product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue()]
    #[ORM\Column()]
    #[Groups(['product:read', 'variant:read', 'value:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['product:read', 'product:write', 'variant:read', 'value:read'])]
    private string $skuRoot;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['product:read', 'product:write'])]
    private bool $isActive = true;

    /**
     * @var Collection<int, ProductVariant>
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductVariant::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['product:read'])]
    private Collection $variants;

    /**
     * @var Collection<int, ProductAttributeValue>
     */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductAttributeValue::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['product:read'])]
    private Collection $attributeValues;

    public function __construct()
    {
        $this->variants = new ArrayCollection();
        $this->attributeValues = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSkuRoot(): string
    {
        return $this->skuRoot;
    }

    public function setSkuRoot(string $skuRoot): self
    {
        $this->skuRoot = $skuRoot;
        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $active): self
    {
        $this->isActive = $active;
        return $this;
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(ProductVariant $variant): self
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
        }
        return $this;
    }

    public function removeVariant(ProductVariant $variant): self
    {
        if ($this->variants->removeElement($variant)) {
            if ($variant->getProduct() === $this) {
                $variant->setProduct(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, ProductAttributeValue>
     */
    public function getAttributeValues(): Collection
    {
        return $this->attributeValues;
    }

    public function addAttributeValue(ProductAttributeValue $value): self
    {
        if (!$this->attributeValues->contains($value)) {
            $this->attributeValues->add($value);
            $value->setProduct($this);
        }
        return $this;
    }

    public function removeAttributeValue(ProductAttributeValue $value): self
    {
        if ($this->attributeValues->removeElement($value)) {
            if ($value->getProduct() === $this) {
                $value->setProduct(null);
            }
        }
        return $this;
    }
}
