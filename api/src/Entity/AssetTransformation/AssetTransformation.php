<?php

namespace App\Entity\AssetTransformation;

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
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'asset_transformation')]
#[UniqueEntity(fields: ['code'], message: 'Une transformation avec ce code existe déjà.')]
#[ApiFilter(SearchFilter::class, properties: ['code' => 'ipartial', 'label' => 'ipartial'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['asset_transformation:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['asset_transformation:write']],
)]
#[MenuGroup('Settings')]
class AssetTransformation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['asset_transformation:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private ?string $code = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    private ?string $label = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['asset_transformation:read'])]
    private ?string $versionHash = null;

    /**
     * @var Collection<int, TransformationStep>
     */
    #[ORM\OneToMany(
        mappedBy: 'transformation',
        targetEntity: TransformationStep::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['asset_transformation:read', 'asset_transformation:write'])]
    #[MaxDepth(1)]
    private Collection $steps;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
    }

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getVersionHash(): ?string
    {
        return $this->versionHash;
    }

    /**
     * @internal Set automatically by TransformationHashListener — do not call from controllers.
     */
    public function setVersionHash(?string $versionHash): static
    {
        $this->versionHash = $versionHash;
        return $this;
    }

    /**
     * @return Collection<int, TransformationStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(TransformationStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setTransformation($this);
        }
        return $this;
    }

    public function removeStep(TransformationStep $step): static
    {
        if ($this->steps->removeElement($step)) {
            if ($step->getTransformation() === $this) {
                $step->setTransformation(null);
            }
        }
        return $this;
    }
}
