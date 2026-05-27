<?php

namespace App\EventListener;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
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

        // 3. Recompute hash for each dirty transformation.
        foreach ($dirty as $transformation) {
            $oldHash = $transformation->getVersionHash();
            $newHash = $this->hasher->compute($transformation);
            if ($newHash === $oldHash) {
                continue;
            }
            $transformation->setVersionHash($newHash);
            $uow->recomputeSingleEntityChangeSet($meta, $transformation);

            if ($oldHash !== null && $transformation->getId() !== null) {
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
}
