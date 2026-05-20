<?php

namespace App\EventListener;

use App\Entity\Product\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
final class ProductVariantDefaultListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(ProductVariant::class);

        $promoted = [];

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ProductVariant && $entity->getIsDefault()) {
                $promoted[] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ProductVariant) {
                continue;
            }
            $changes = $uow->getEntityChangeSet($entity);
            if (isset($changes['isDefault']) && $changes['isDefault'][1] === true) {
                $promoted[] = $entity;
            }
        }

        if (!$promoted) {
            return;
        }

        $repo = $em->getRepository(ProductVariant::class);

        foreach ($promoted as $variant) {
            $product = $variant->getProduct();
            if (!$product) {
                continue;
            }

            $qb = $repo->createQueryBuilder('v')
                ->where('v.product = :product')
                ->andWhere('v.isDefault = true')
                ->setParameter('product', $product);

            if ($variant->getId() !== null) {
                $qb->andWhere('v.id != :currentId')
                    ->setParameter('currentId', $variant->getId());
            }

            $siblings = $qb->getQuery()->getResult();

            foreach ($siblings as $sibling) {
                if ($sibling === $variant) {
                    continue;
                }
                $sibling->setIsDefault(false);
                $uow->recomputeSingleEntityChangeSet($metadata, $sibling);
            }
        }
    }
}