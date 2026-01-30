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
use App\Enum\AttributeType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator as AppAssert;

#[ORM\Entity]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'code' => 'ipartial', 'type' => 'exact'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['attribute_definition:read']],
    denormalizationContext: ['groups' => ['attribute_definition:write']]
)]
#[MenuGroup('Settings')]
#[AppAssert\RelationEndpointImmutableIfUsed]
class AttributeDefinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['attribute_definition:read', 'value:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[AppAssert\Code]
    #[Groups(['attribute_definition:read', 'attribute_definition:write', 'value:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 50, enumType: AttributeType::class)]
    #[Assert\NotBlank]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private ?AttributeType $type = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['attribute_definition:read', 'attribute_definition:write', 'value:read'])]
    private bool $isLocalizable = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['attribute_definition:read', 'attribute_definition:write', 'value:read'])]
    private bool $isScopable = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private ?array $validationRules = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private ?array $allowedValues = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private ?string $unit = null;

    #[ORM\Column]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private bool $isRequired = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private ?string $defaultValue = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private ?string $helpText = null;

    #[ORM\Column]
    #[Groups(['attribute_definition:read', 'attribute_definition:write'])]
    private int $sortOrder = 0;

    /**
     * API endpoint path for relation type attributes (e.g., "/api/collections", "/api/products")
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['attribute_definition:read', 'attribute_definition:write', 'value:read'])]
    private ?string $relationEndpoint = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getType(): ?AttributeType
    {
        return $this->type;
    }

    public function setType(AttributeType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getIsLocalizable(): bool
    {
        return $this->isLocalizable;
    }

    public function setIsLocalizable(bool $isLocalizable): static
    {
        $this->isLocalizable = $isLocalizable;
        return $this;
    }

    public function getIsScopable(): bool
    {
        return $this->isScopable;
    }

    public function setIsScopable(bool $isScopable): static
    {
        $this->isScopable = $isScopable;
        return $this;
    }

    public function getValidationRules(): ?array
    {
        return $this->validationRules;
    }

    public function setValidationRules(?array $validationRules): static
    {
        $this->validationRules = $validationRules;
        return $this;
    }

    public function getAllowedValues(): ?array
    {
        return $this->allowedValues;
    }

    public function setAllowedValues(?array $allowedValues): static
    {
        $this->allowedValues = $allowedValues;
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function getIsRequired(): bool
    {
        return $this->isRequired;
    }

    public function setIsRequired(bool $isRequired): static
    {
        $this->isRequired = $isRequired;
        return $this;
    }

    public function getDefaultValue(): ?string
    {
        return $this->defaultValue;
    }

    public function setDefaultValue(?string $defaultValue): static
    {
        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    public function setHelpText(?string $helpText): static
    {
        $this->helpText = $helpText;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getRelationEndpoint(): ?string
    {
        return $this->relationEndpoint;
    }

    public function setRelationEndpoint(?string $relationEndpoint): static
    {
        $this->relationEndpoint = $relationEndpoint;
        return $this;
    }
}
