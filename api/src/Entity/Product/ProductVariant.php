<?php

namespace App\Entity\Product;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\McpToolCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use App\ApiResource\Mcp\IdentifierInput;
use App\ApiResource\Mcp\ProductVariantCreateInput;
use App\ApiResource\Mcp\ProductVariantSearch;
use App\ApiResource\Mcp\ProductVariantUpdateInput;
use App\State\Mcp\ProductVariantCreateProcessor;
use App\State\Mcp\ProductVariantUpdateProcessor;
use App\Attribute\MenuGroup;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'product_variant')]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(),
        new Patch(),
        new Delete()
    ],
    mcp: [
        'list_product_variants' => new McpToolCollection(
            description: 'List product variants. Filter by parent product id, sku (partial), or ean.',
            input: ProductVariantSearch::class,
            provider: CollectionProvider::class,
        ),
        'get_product_variant' => new McpTool(
            description: 'Get a single product variant by id, including its attribute values.',
            uriTemplate: '/product_variants/{id}',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['id'])],
            input: IdentifierInput::class,
            provider: ItemProvider::class,
        ),
        'create_product_variant' => new McpTool(
            description: 'Create a new variant under an existing product. Requires productId and sku.',
            input: ProductVariantCreateInput::class,
            processor: ProductVariantCreateProcessor::class,
        ),
        'update_product_variant' => new McpTool(
            description: 'Update fields of an existing variant. Setting isDefault=true automatically resets the previous default for the same product.',
            input: ProductVariantUpdateInput::class,
            processor: ProductVariantUpdateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['variant:read']],
    denormalizationContext: ['groups' => ['variant:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'product' => 'exact', 'sku' => 'ipartial', 'ean' => 'exact'])]
#[MenuGroup('Product')]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue()]
    #[ORM\Column()]
    #[Groups(['variant:read', 'product:read', 'value:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['variant:read', 'variant:write'])]
    private ?Product $product = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Groups(['variant:read', 'variant:write'])]
    private string $sku;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['variant:read', 'variant:write'])]
    private ?string $ean = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['variant:read', 'variant:write'])]
    private bool $isDefault = false;

    /**
     * @var Collection<int, ProductAttributeValue>
     */
    #[ORM\OneToMany(mappedBy: 'variant', targetEntity: ProductAttributeValue::class, cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['variant:read'])]
    private Collection $attributeValues;

    public function __construct()
    {
        $this->attributeValues = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;
        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): self
    {
        $this->ean = $ean;
        return $this;
    }

    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $default): self
    {
        $this->isDefault = $default;
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
            $value->setVariant($this);
        }
        return $this;
    }

    public function removeAttributeValue(ProductAttributeValue $value): self
    {
        if ($this->attributeValues->removeElement($value)) {
            if ($value->getVariant() === $this) {
                $value->setVariant(null);
            }
        }
        return $this;
    }
}
