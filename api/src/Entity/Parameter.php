<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\Enum\ParameterType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(
    name: 'parameter',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_parameter_code', columns: ['code']),
    ]
)]
#[ApiResource(
    shortName: 'Parameter',
    operations: [
        new Get(),
        new GetCollection(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    order: ['code' => 'ASC'],
    normalizationContext: ['groups' => ['parameter:read']],
    denormalizationContext: ['groups' => ['parameter:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['id' => 'exact', 'code' => 'ipartial', 'type' => 'exact'])]
#[MenuGroup('System')]
class Parameter
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['parameter:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[A-Z][A-Z0-9_]*$/', message: 'Parameter code must be UPPER_SNAKE_CASE (e.g. TAXONOMY_LEVEL_MAX).')]
    #[Groups(['parameter:read', 'parameter:write'])]
    private string $code;

    #[ORM\Column(type: 'text')]
    #[Groups(['parameter:read', 'parameter:write'])]
    private string $value = '';

    #[ORM\Column(length: 20, enumType: ParameterType::class)]
    #[Groups(['parameter:read', 'parameter:write'])]
    private ParameterType $type = ParameterType::STRING;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['parameter:read', 'parameter:write'])]
    private ?string $description = null;

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

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getType(): ParameterType
    {
        return $this->type;
    }

    public function setType(ParameterType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function asInt(): int
    {
        return (int) $this->value;
    }

    public function asBool(): bool
    {
        return \filter_var($this->value, \FILTER_VALIDATE_BOOLEAN);
    }

    public function asJson(): mixed
    {
        return $this->value === '' ? null : \json_decode($this->value, true);
    }
}
