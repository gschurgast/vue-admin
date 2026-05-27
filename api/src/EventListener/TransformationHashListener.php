<?php

namespace App\EventListener;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Message\PurgeTransformationVariantsMessage;
use App\Service\AssetTransformation\TransformationHasher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class TransformationHashListener
{
    /** @var list<PurgeTransformationVariantsMessage> */
    private array $pendingPurges = [];

    public function __construct(
        private readonly TransformationHasher $hasher,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $meta = $em->getClassMetadata(AssetTransformation::class);

        /** @var array<int, AssetTransformation> $dirty — dedup by id or spl_object_id (Pitfall G) */
        $dirty = [];

        // 1. Step insertions/updates/deletions bubble up to their parent transformation (Pitfall A).
        foreach ([
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
            ...$uow->getScheduledEntityDeletions(),
        ] as $entity) {
            if ($entity instanceof TransformationStep) {
                $parent = $entity->getTransformation();
                if ($parent !== null) {
                    $key = $parent->getId() ?? -spl_object_id($parent);
                    $dirty[$key] = $parent;
                }
            }
        }

        // 2. AssetTransformation direct insertions/updates.
        foreach ([
            ...$uow->getScheduledEntityInsertions(),
            ...$uow->getScheduledEntityUpdates(),
        ] as $entity) {
            if ($entity instanceof AssetTransformation) {
                $key = $entity->getId() ?? -spl_object_id($entity);
                $dirty[$key] = $entity;
            }
        }

        // 2b. Collection mutations on AssetTransformation::$steps (covers removeStep,
        // which nullifies the inverse side before orphanRemoval deletes the step).
        foreach ([
            ...$uow->getScheduledCollectionUpdates(),
            ...$uow->getScheduledCollectionDeletions(),
        ] as $collection) {
            $owner = $collection->getOwner();
            $mapping = $collection->getMapping();
            if ($owner instanceof AssetTransformation && ($mapping['fieldName'] ?? null) === 'steps') {
                $key = $owner->getId() ?? -spl_object_id($owner);
                $dirty[$key] = $owner;
            }
        }

        // 3. Recompute hash + warnings for each dirty transformation.
        foreach ($dirty as $transformation) {
            $oldHash = $transformation->getVersionHash();
            $newHash = $this->hasher->compute($transformation);
            $newWarnings = $this->computeWarnings($transformation);
            $oldWarnings = $transformation->getWarnings();

            $hashChanged = $newHash !== $oldHash;
            $warningsChanged = $newWarnings !== $oldWarnings;

            if (!$hashChanged && !$warningsChanged) {
                continue;
            }

            if ($hashChanged) {
                $transformation->setVersionHash($newHash);
            }
            if ($warningsChanged) {
                $transformation->setWarnings($newWarnings);
            }
            $uow->recomputeSingleEntityChangeSet($meta, $transformation);

            if ($hashChanged && $oldHash !== null && $transformation->getId() !== null) {
                $this->pendingPurges[] = new PurgeTransformationVariantsMessage(
                    $transformation->getId(),
                    $oldHash,
                );
            }
        }

        // 4. AssetTransformation deletions — capture hash BEFORE the row vanishes (Pitfall C).
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof AssetTransformation
                && $entity->getId() !== null
                && $entity->getVersionHash() !== null
            ) {
                $this->pendingPurges[] = new PurgeTransformationVariantsMessage(
                    $entity->getId(),
                    $entity->getVersionHash(),
                );
            }
        }
    }

    public function postFlush(): void
    {
        foreach ($this->pendingPurges as $msg) {
            $this->bus->dispatch($msg);
        }
        $this->pendingPurges = [];
    }

    /**
     * Derive persisted warnings from the step chain (HANDLERS-05).
     *
     * Currently a single heuristic: `alpha-flatten-on-jpeg` — fired when the
     * pipeline ends with a JPEG format_convert but does NOT contain an
     * add_background step. The Python embedder will flatten on white in that
     * case (Phase 2 SC #3), losing any transparency.
     *
     * @return array<int, array{code: string, stepIndex: int|null}>
     */
    private function computeWarnings(AssetTransformation $tx): array
    {
        $steps = $tx->getSteps()->toArray();
        // Defensive sort: collection OrderBy(position ASC) is normally enough,
        // but a freshly-mutated collection may not yet be ordered.
        usort($steps, fn (TransformationStep $a, TransformationStep $b) => $a->getPosition() <=> $b->getPosition());

        $hasJpegConvert = false;
        $hasAddBackground = false;
        foreach ($steps as $step) {
            $type = $step->getType();
            if ($type === StepType::FORMAT_CONVERT) {
                $fmt = $step->getParams()['format'] ?? null;
                if (\in_array($fmt, ['jpg', 'jpeg'], true)) {
                    $hasJpegConvert = true;
                }
            } elseif ($type === StepType::ADD_BACKGROUND) {
                $hasAddBackground = true;
            }
        }

        $warnings = [];
        if ($hasJpegConvert && !$hasAddBackground) {
            $warnings[] = ['code' => 'alpha-flatten-on-jpeg', 'stepIndex' => null];
        }
        return $warnings;
    }
}
