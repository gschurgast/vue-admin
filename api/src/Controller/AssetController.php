<?php

namespace App\Controller;

use App\Entity\Asset\Asset;
use App\Enum\AssetType;
use App\Service\Asset\AssetUploadException;
use App\Service\Asset\AssetUploader;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[AsController]
class AssetController
{
    public function __construct(
        private readonly AssetUploader $uploader,
        private readonly EntityManagerInterface $em,
        private readonly SerializerInterface $serializer,
        #[Autowire(service: 'assets.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    /**
     * Multipart upload — accepts one or many files under the "files[]" field.
     * Returns an array with one entry per file: { success: true, asset: {...} } or { success: false, filename, error }.
     */
    #[Route('/api/assets/upload', name: 'asset_upload', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function upload(Request $request): JsonResponse
    {
        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[] $files */
        $files = $request->files->all('files');
        if (empty($files)) {
            // Fallback: support a single "file" field for convenience.
            $single = $request->files->get('file');
            if ($single !== null) {
                $files = [$single];
            }
        }

        if (empty($files)) {
            return new JsonResponse(['error' => 'No file uploaded (expected "files[]" or "file").'], Response::HTTP_BAD_REQUEST);
        }

        $typeParam = $request->request->get('type');
        $forcedType = $typeParam ? AssetType::tryFrom((string) $typeParam) : null;

        $rawFlags = $request->request->all('flags');
        $flagCodes = is_array($rawFlags) ? array_values(array_filter($rawFlags, 'is_string')) : null;

        $results = [];
        foreach ($files as $file) {
            try {
                $outcome = $this->uploader->uploadResult($file, $forcedType, null, $flagCodes ?: null);
                $results[] = [
                    'success' => true,
                    'duplicate' => $outcome->duplicate,
                    'asset' => json_decode(
                        $this->serializer->serialize($outcome->asset, 'jsonld', ['groups' => ['asset:read']]),
                        true,
                    ),
                ];
            } catch (AssetUploadException $e) {
                $results[] = [
                    'success' => false,
                    'filename' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        $allOk = !in_array(false, array_column($results, 'success'), true);
        return new JsonResponse(
            ['results' => $results],
            $allOk ? Response::HTTP_CREATED : Response::HTTP_MULTI_STATUS,
        );
    }

    /**
     * Returns assets visually similar to {id} (cosine similarity >= 0.75 by default),
     * ordered by descending similarity. Includes the strict-duplicate match if any.
     */
    #[Route('/api/assets/{id}/similar', name: 'asset_similar', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function similar(int $id, Request $request): JsonResponse
    {
        $asset = $this->em->find(Asset::class, $id);
        if ($asset === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
        if ($asset->getEmbeddingStatus() !== 'ready') {
            return new JsonResponse([
                'status' => $asset->getEmbeddingStatus(),
                'results' => [],
            ]);
        }

        $minScore = max(0.0, min(1.0, (float) $request->query->get('min', '0.75')));
        $limit = max(1, min(50, (int) $request->query->get('limit', '20')));

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT a.id, a.code, a.filename, a.mime_type, a.type, a.size, a.width, a.height,
                    1 - (a.embedding <=> b.embedding) AS similarity
             FROM asset a, asset b
             WHERE b.id = :id
               AND a.id <> b.id
               AND a.embedding IS NOT NULL
               AND a.embedding_status = :status
               AND (1 - (a.embedding <=> b.embedding)) >= :min
             ORDER BY a.embedding <=> b.embedding
             LIMIT :limit',
            ['id' => $id, 'status' => 'ready', 'min' => $minScore, 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        $results = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                '@id' => '/api/assets/' . (int) $row['id'],
                'code' => $row['code'],
                'filename' => $row['filename'],
                'mimeType' => $row['mime_type'],
                'type' => $row['type'],
                'size' => (int) $row['size'],
                'width' => $row['width'] !== null ? (int) $row['width'] : null,
                'height' => $row['height'] !== null ? (int) $row['height'] : null,
                'similarity' => round((float) $row['similarity'], 4),
            ];
        }, $rows);

        return new JsonResponse([
            'status' => 'ready',
            'duplicateOfId' => $asset->getDuplicateOf()?->getId(),
            'results' => $results,
        ]);
    }

    /**
     * Streams the binary content of an asset.
     * Works identically in dev (local FS) and prod (S3) thanks to Flysystem.
     */
    #[Route('/api/assets/{id}/content', name: 'asset_content', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function content(int $id, Request $request): Response
    {
        $asset = $this->em->find(Asset::class, $id);
        if ($asset === null || $asset->getS3Key() === null) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        $key = $asset->getS3Key();
        if (!$this->storage->fileExists($key)) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Asset file is missing on storage.');
        }

        $disposition = $request->query->getBoolean('download') ? 'attachment' : 'inline';

        $response = new StreamedResponse(function () use ($key) {
            $stream = $this->storage->readStream($key);
            if (!is_resource($stream)) {
                return;
            }
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        $response->headers->set('Content-Type', $asset->getMimeType() ?: 'application/octet-stream');
        $response->headers->set('Content-Length', (string) $asset->getSize());
        $response->headers->set(
            'Content-Disposition',
            sprintf('%s; filename="%s"', $disposition, addslashes($asset->getFilename() ?: 'asset')),
        );
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }
}