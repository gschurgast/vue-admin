<?php

namespace App\Entity\Asset;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Attribute\MenuGroup;
use App\Enum\AssetType;
use App\State\AssetDeleteProcessor;
use App\Validator as AppAssert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'asset')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'], message: 'Un asset avec ce code existe déjà.')]
#[UniqueEntity(fields: ['s3Key'], message: 'Un asset utilise déjà cette clé de stockage.', ignoreNull: true)]
#[ApiFilter(SearchFilter::class, properties: [
    'id' => 'exact',
    'code' => 'ipartial',
    'type' => 'exact',
    'filename' => 'ipartial',
    'mimeType' => 'exact',
    'flags.code' => 'exact',
])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Patch(),
        new Delete(security: "is_granted('ROLE_ADMIN')", processor: AssetDeleteProcessor::class),
    ],
    normalizationContext: ['groups' => ['asset:read'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['asset:write']],
)]
#[MenuGroup('Catalog')]
class Asset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['asset:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[AppAssert\Code]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $code = null;

    #[ORM\Column(length: 20, enumType: AssetType::class)]
    #[Assert\NotNull]
    #[Groups(['asset:read', 'asset:write'])]
    private ?AssetType $type = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['asset:read'])]
    private ?string $mimeType = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $filename = null;

    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    #[Groups(['asset:read'])]
    private int $size = 0;

    #[ORM\Column(length: 512, unique: true, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $s3Key = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $s3Bucket = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $height = null;

    /**
     * Duration in seconds (video / audio).
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['asset:read'])]
    private ?int $duration = null;

    /**
     * SHA-256 checksum of the binary content (for deduplication).
     */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $checksum = null;

    /**
     * CLIP image embedding (L2-normalised, 512-d). Indexed via HNSW for ANN search.
     * Stored as Pgvector\Vector; the column is `vector(512)` on Postgres.
     */
    #[ORM\Column(type: 'vector', length: 512, nullable: true)]
    private ?\Pgvector\Vector $embedding = null;

    /**
     * Embedding model identifier (e.g. "clip-ViT-B-32"). Lets us detect stale
     * vectors when the model is upgraded — they can then be recomputed.
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['asset:read'])]
    private ?string $embeddingModel = null;

    /**
     * One of: pending | ready | failed | skipped.
     * The async worker transitions pending → ready/failed.
     */
    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    #[Groups(['asset:read'])]
    private string $embeddingStatus = 'pending';

    /**
     * If this asset was uploaded but matched another above the strict duplicate
     * threshold, it points to the canonical existing asset. The upload still
     * succeeds (we keep the file), but UI can flag it.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'duplicate_of_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['asset:read'])]
    #[MaxDepth(1)]
    private ?Asset $duplicateOf = null;

    /**
     * @var Collection<int, AssetFlag>
     */
    #[ORM\ManyToMany(targetEntity: AssetFlag::class)]
    #[ORM\JoinTable(name: 'asset_asset_flag')]
    #[Groups(['asset:read', 'asset:write'])]
    #[MaxDepth(1)]
    private Collection $flags;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['asset:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['asset:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->flags = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Build the S3 storage key from the asset id.
     * Layout: {shard}/{id}.{ext} where shard = floor(id / 1000).
     */
    public static function computeS3Key(int $id, string $extension): string
    {
        $shard = intdiv($id, 1000);
        $ext = ltrim($extension, '.');
        return sprintf('%d/%d.%s', $shard, $id, $ext);
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

    public function getType(): ?AssetType
    {
        return $this->type;
    }

    public function setType(AssetType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getS3Key(): ?string
    {
        return $this->s3Key;
    }

    public function setS3Key(?string $s3Key): static
    {
        $this->s3Key = $s3Key;
        return $this;
    }

    public function getS3Bucket(): ?string
    {
        return $this->s3Bucket;
    }

    public function setS3Bucket(?string $s3Bucket): static
    {
        $this->s3Bucket = $s3Bucket;
        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): static
    {
        $this->width = $width;
        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): static
    {
        $this->height = $height;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(?int $duration): static
    {
        $this->duration = $duration;
        return $this;
    }

    public function getEmbedding(): ?\Pgvector\Vector
    {
        return $this->embedding;
    }

    public function setEmbedding(?\Pgvector\Vector $embedding): static
    {
        $this->embedding = $embedding;
        return $this;
    }

    public function getEmbeddingModel(): ?string
    {
        return $this->embeddingModel;
    }

    public function setEmbeddingModel(?string $embeddingModel): static
    {
        $this->embeddingModel = $embeddingModel;
        return $this;
    }

    public function getEmbeddingStatus(): string
    {
        return $this->embeddingStatus;
    }

    public function setEmbeddingStatus(string $embeddingStatus): static
    {
        $this->embeddingStatus = $embeddingStatus;
        return $this;
    }

    public function getDuplicateOf(): ?Asset
    {
        return $this->duplicateOf;
    }

    public function setDuplicateOf(?Asset $duplicateOf): static
    {
        $this->duplicateOf = $duplicateOf;
        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): static
    {
        $this->checksum = $checksum;
        return $this;
    }

    /**
     * @return Collection<int, AssetFlag>
     */
    public function getFlags(): Collection
    {
        return $this->flags;
    }

    public function addFlag(AssetFlag $flag): static
    {
        if (!$this->flags->contains($flag)) {
            $this->flags->add($flag);
        }
        return $this;
    }

    public function removeFlag(AssetFlag $flag): static
    {
        $this->flags->removeElement($flag);
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}