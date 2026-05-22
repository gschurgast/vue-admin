<?php

namespace App\MessageHandler;

use App\Entity\Asset\Asset;
use App\Entity\Asset\AssetSimilarity;
use App\Enum\AssetType;
use App\Message\ComputeEmbeddingMessage;
use App\Service\Asset\AssetEmbedder;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Pgvector\Vector;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async handler:
 *   1. Loads the asset and its binary from storage.
 *   2. Calls the embedder microservice to obtain a 512-d CLIP vector.
 *   3. Saves the vector + searches for visually similar existing assets.
 *   4. Marks strong duplicates (cosine >= DUPLICATE_THRESHOLD) and stores
 *      similarity links for moderate matches (>= SIMILAR_THRESHOLD).
 *
 * Only images are embedded; other types are marked `skipped`.
 */
#[AsMessageHandler]
class ComputeEmbeddingHandler
{
    /** Cosine similarity above this is treated as the same image. */
    public const DUPLICATE_THRESHOLD = 0.95;

    /** Cosine similarity above this is stored as an asset_similarity link. */
    public const SIMILAR_THRESHOLD = 0.75;

    /** How many neighbours to consider when scanning for matches. */
    private const MAX_NEIGHBOURS = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AssetEmbedder $embedder,
        #[Autowire(service: 'assets.storage')]
        private readonly FilesystemOperator $storage,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ComputeEmbeddingMessage $message): void
    {
        $asset = $this->em->find(Asset::class, $message->assetId);
        if ($asset === null) {
            $this->logger->info('Asset {id} no longer exists, skipping embedding.', ['id' => $message->assetId]);
            return;
        }

        if ($asset->getType() !== AssetType::IMAGE) {
            $asset->setEmbeddingStatus('skipped');
            $this->em->flush();
            return;
        }

        if ($asset->getS3Key() === null || !$this->storage->fileExists($asset->getS3Key())) {
            $asset->setEmbeddingStatus('failed');
            $this->em->flush();
            $this->logger->warning('Asset {id} has no readable storage object, marking failed.', ['id' => $asset->getId()]);
            return;
        }

        try {
            $stream = $this->storage->readStream($asset->getS3Key());
            $raw = is_resource($stream) ? stream_get_contents($stream) : '';
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($raw === '' || $raw === false) {
                throw new \RuntimeException('Empty content read from storage');
            }

            $result = $this->embedder->embed($raw, $asset->getFilename() ?? 'image', $asset->getMimeType() ?? 'application/octet-stream');
            $vector = new Vector($result['embedding']);

            $asset->setEmbedding($vector);
            $asset->setEmbeddingModel($result['model']);
            $asset->setEmbeddingStatus('ready');
            $this->em->flush();

            $this->detectDuplicatesAndSimilar($asset, $vector);
        } catch (\Throwable $e) {
            $asset->setEmbeddingStatus('failed');
            $this->em->flush();
            $this->logger->error('Embedding failed for asset {id}: {msg}', [
                'id' => $asset->getId(),
                'msg' => $e->getMessage(),
                'exception' => $e,
            ]);
            throw $e; // let Messenger retry
        }
    }

    /**
     * Runs an ANN query in pgvector to find the closest neighbours and links/marks
     * them according to the thresholds.
     */
    private function detectDuplicatesAndSimilar(Asset $asset, Vector $vector): void
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT id, 1 - (embedding <=> :vec) AS similarity
             FROM asset
             WHERE id <> :id
               AND embedding IS NOT NULL
               AND embedding_status = :status
             ORDER BY embedding <=> :vec
             LIMIT :limit',
            [
                'vec' => (string) $vector,
                'id' => $asset->getId(),
                'status' => 'ready',
                'limit' => self::MAX_NEIGHBOURS,
            ],
            [
                'limit' => ParameterType::INTEGER,
            ],
        );

        if ($rows === []) {
            return;
        }

        $duplicateMarked = false;
        foreach ($rows as $row) {
            $score = (float) $row['similarity'];
            $otherId = (int) $row['id'];

            if ($score >= self::DUPLICATE_THRESHOLD && !$duplicateMarked) {
                $other = $this->em->getReference(Asset::class, $otherId);
                $asset->setDuplicateOf($other);
                $duplicateMarked = true;
            }

            if ($score >= self::SIMILAR_THRESHOLD) {
                $this->upsertSimilarity($asset->getId(), $otherId, $score);
            }
        }

        $this->em->flush();
    }

    /**
     * Inserts (or updates) an asset_similarity row. The CHECK constraint on the
     * table enforces (a_id < b_id), so we canonicalise before INSERT.
     */
    private function upsertSimilarity(int $aId, int $bId, float $score): void
    {
        [$lo, $hi] = $aId < $bId ? [$aId, $bId] : [$bId, $aId];
        $this->em->getConnection()->executeStatement(
            'INSERT INTO asset_similarity (asset_a_id, asset_b_id, score)
             VALUES (:a, :b, :s)
             ON CONFLICT (asset_a_id, asset_b_id) DO UPDATE SET score = EXCLUDED.score',
            ['a' => $lo, 'b' => $hi, 's' => $score],
        );
    }
}
