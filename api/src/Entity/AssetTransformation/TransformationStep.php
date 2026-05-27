<?php

namespace App\Entity\AssetTransformation;

use ApiPlatform\Metadata\ApiResource;
use App\Attribute\MenuGroup;
use App\Enum\StepType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'transformation_step')]
#[ApiResource(operations: [])]
#[MenuGroup('hidden')]
class TransformationStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['asset_transformation:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AssetTransformation::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[MaxDepth(1)]
    private ?AssetTransformation $transformation = null;

    #[ORM\Column(length: 30, enumType: StepType::class)]
    #[Assert\NotNull]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private ?StepType $type = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Assert\Type('array')]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private array $params = [];

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTransformation(): ?AssetTransformation
    {
        return $this->transformation;
    }

    public function setTransformation(?AssetTransformation $transformation): static
    {
        $this->transformation = $transformation;
        return $this;
    }

    public function getType(): ?StepType
    {
        return $this->type;
    }

    public function setType(StepType $type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): static
    {
        $this->params = $params;
        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;
        return $this;
    }
}
