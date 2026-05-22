<?php

namespace App\Service\Asset;

use App\Entity\Asset\Asset;
use App\Enum\AssetType;
use App\Message\ComputeEmbeddingMessage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Persists an uploaded file as an Asset entity and writes its binary content
 * to the configured Flysystem storage (local in dev/test, S3 in prod).
 *
 * Two-phase persist is required because the S3 key embeds the asset id:
 *   1. Persist + flush to get the auto-generated id.
 *   2. Compute s3Key, stream the file to storage, flush again.
 *
 * On storage failure after the first flush, the half-persisted Asset row is
 * removed to avoid orphan DB records pointing to nothing.
 */
class AssetUploader
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'assets.storage')]
        private readonly FilesystemOperator $storage,
        private readonly SluggerInterface $slugger,
        private readonly AssetMetadataExtractor $metadataExtractor,
        private readonly MessageBusInterface $messageBus,
        #[Autowire(env: 'default::S3_ASSETS_BUCKET')]
        private readonly ?string $bucket = null,
    ) {
    }

    /**
     * Outcome wrapper to expose deduplication to callers.
     */
    public function uploadResult(
        UploadedFile $file,
        ?AssetType $type = null,
        ?string $code = null,
        ?array $flagCodes = null,
    ): AssetUploadResult {
        return $this->doUpload($file, $type, $code, $flagCodes);
    }

    /**
     * @param string[]|null $flagCodes optional flag codes to attach
     */
    public function upload(
        UploadedFile $file,
        ?AssetType $type = null,
        ?string $code = null,
        ?array $flagCodes = null,
    ): Asset {
        return $this->doUpload($file, $type, $code, $flagCodes)->asset;
    }

    /**
     * @param string[]|null $flagCodes
     */
    private function doUpload(
        UploadedFile $file,
        ?AssetType $type,
        ?string $code,
        ?array $flagCodes,
    ): AssetUploadResult {
        if (!$file->isValid()) {
            throw new AssetUploadException(sprintf('Invalid upload: %s', $file->getErrorMessage()));
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $resolvedType = $type ?? AssetType::fromMimeType($mimeType);
        if ($resolvedType === null) {
            throw new AssetUploadException(sprintf('Unsupported mime type "%s".', $mimeType));
        }

        if (!in_array($mimeType, $resolvedType->allowedMimeTypes(), true)) {
            throw new AssetUploadException(sprintf(
                'Mime type "%s" is not allowed for asset type "%s".',
                $mimeType,
                $resolvedType->value,
            ));
        }

        $size = $file->getSize() ?: 0;
        if ($size > $resolvedType->maxSize()) {
            throw new AssetUploadException(sprintf(
                'File size %d bytes exceeds limit %d for type "%s".',
                $size,
                $resolvedType->maxSize(),
                $resolvedType->value,
            ));
        }

        $originalName = $file->getClientOriginalName();
        $checksum = hash_file('sha256', $file->getPathname()) ?: null;

        // Deduplication: if a previous asset shares the same checksum *and* is still
        // attached to a storage object, return it as-is (no new row, no upload).
        if ($checksum !== null) {
            $existing = $this->em->getRepository(Asset::class)
                ->findOneBy(['checksum' => $checksum]);
            if ($existing !== null && $existing->getS3Key() !== null) {
                return new AssetUploadResult($existing, duplicate: true);
            }
        }

        $metadata = $this->metadataExtractor->extract($file->getPathname(), $resolvedType, $mimeType);

        $asset = new Asset();
        $asset->setCode($code ?? $this->generateCode($originalName));
        $asset->setType($resolvedType);
        $asset->setMimeType($mimeType);
        $asset->setFilename($originalName);
        $asset->setSize($size);
        $asset->setChecksum($checksum);
        $asset->setWidth($metadata['width']);
        $asset->setHeight($metadata['height']);
        $asset->setDuration($metadata['duration']);
        if ($this->bucket !== null && $this->bucket !== '') {
            $asset->setS3Bucket($this->bucket);
        }

        if ($flagCodes) {
            $flags = $this->em->getRepository(\App\Entity\Asset\AssetFlag::class)
                ->findBy(['code' => $flagCodes]);
            foreach ($flags as $flag) {
                $asset->addFlag($flag);
            }
        }

        // Phase 1: persist to obtain the id.
        $this->em->persist($asset);
        $this->em->flush();

        // Phase 2: stream file content to storage under the sharded key.
        $extension = $this->resolveExtension($file, $originalName);
        $key = Asset::computeS3Key((int) $asset->getId(), $extension);

        try {
            $stream = fopen($file->getPathname(), 'rb');
            if ($stream === false) {
                throw new AssetUploadException('Unable to open uploaded file for reading.');
            }
            try {
                $this->storage->writeStream($key, $stream, ['mimetype' => $mimeType]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } catch (\Throwable $e) {
            $this->em->remove($asset);
            $this->em->flush();
            throw new AssetUploadException(
                sprintf('Failed to store asset content: %s', $e->getMessage()),
                previous: $e,
            );
        }

        $asset->setS3Key($key);
        $this->em->flush();

        // Dispatch async embedding for images. Other types stay `pending` until the
        // handler downgrades them to `skipped`. The redis transport buffers the
        // batch so a drop of 50 files queues 50 jobs at almost no synchronous cost.
        if ($resolvedType === AssetType::IMAGE) {
            $this->messageBus->dispatch(new ComputeEmbeddingMessage((int) $asset->getId()));
        }

        return new AssetUploadResult($asset, duplicate: false);
    }

    public function delete(Asset $asset): void
    {
        $key = $asset->getS3Key();
        if ($key !== null && $this->storage->fileExists($key)) {
            $this->storage->delete($key);
        }
        $this->em->remove($asset);
        $this->em->flush();
    }

    private function generateCode(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = strtolower((string) $this->slugger->slug($base));
        $slug = substr($slug, 0, 32) ?: 'asset';
        return sprintf('%s-%s', $slug, bin2hex(random_bytes(4)));
    }

    private function resolveExtension(UploadedFile $file, string $originalName): string
    {
        $ext = $file->guessExtension();
        if ($ext !== null && $ext !== '') {
            return $ext;
        }
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        return $ext !== '' ? strtolower($ext) : 'bin';
    }
}
