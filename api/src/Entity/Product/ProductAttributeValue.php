<?php

namespace App\Entity\Product;

use ApiPlatform\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use App\Attribute\MenuGroup;
use App\Enum\Market;
use App\Entity\Attribute\AttributeDefinition;
use App\Entity\Attribute\AttributeOption;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(
    name: 'product_attribute_value',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_product_variant_attribute',
            columns: ['product_id', 'variant_id', 'attribute_definition_id']
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
    normalizationContext: ['groups' => ['value:read']],
    denormalizationContext: ['groups' => ['value:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'product' => 'exact', 'variant' => 'exact', 'attributeDefinition' => 'exact', 'locale' => 'exact', 'market' => 'exact'])]
#[ApiFilter(ExistsFilter::class, properties: ['variant'])]
#[MenuGroup('Attribute')]
class ProductAttributeValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue()]
    #[ORM\Column()]
    #[Groups(['value:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'attributeValues')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['value:read', 'value:write'])]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'attributeValues')]
    #[Groups(['value:read', 'value:write'])]
    private ?ProductVariant $variant = null;

    #[ORM\ManyToOne(targetEntity: AttributeDefinition::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['value:read', 'value:write'])]
    private AttributeDefinition $attributeDefinition;

    #[ORM\ManyToOne(targetEntity: AttributeOption::class)]
    #[Groups(['value:read', 'value:write'])]
    private ?AttributeOption $option = null;

    #[ORM\Column(type: 'string', length: 5, nullable: true)]
    #[Groups(['value:read', 'value:write'])]
    private ?string $locale = null;

    #[ORM\Column(enumType: Market::class, nullable: true)]
    #[Groups(['value:read', 'value:write'])]
    private ?Market $market = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['value:read', 'value:write'])]
    private ?string $value = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['value:read', 'value:write'])]
    private ?array $values = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function setVariant(?ProductVariant $variant): self
    {
        $this->variant = $variant;
        return $this;
    }

    public function getAttributeDefinition(): AttributeDefinition
    {
        return $this->attributeDefinition;
    }

    public function setAttributeDefinition(AttributeDefinition $def): self
    {
        $this->attributeDefinition = $def;
        return $this;
    }

    public function getOption(): ?AttributeOption
    {
        return $this->option;
    }

    public function setOption(?AttributeOption $opt): self
    {
        $this->option = $opt;
        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function getMarket(): ?Market
    {
        return $this->market;
    }

    public function setMarket(?Market $market): self
    {
        $this->market = $market;
        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getValues(): ?array
    {
        return $this->values;
    }

    public function setValues(?array $values): self
    {
        $this->values = $values;
        return $this;
    }
}
