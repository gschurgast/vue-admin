<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\AssetTransformation\TransformationStep;
use App\Service\AssetTransformation\StepParams\StepParamsFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Doctrine lifecycle listener — validates every persisted/updated
 * TransformationStep against its StepType-specific DTO (D-16, HANDLERS-03).
 *
 * Covers ALL write paths (API Platform POST/PATCH, fixtures, console commands)
 * because the hook fires at the ORM layer rather than the HTTP layer. The
 * thrown ValidationFailedException is converted by API Platform to a 422
 * automatically; on the console / fixtures path the exception simply bubbles
 * up with the violation list.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final class TransformationStepValidationListener
{
    public function __construct(
        private readonly StepParamsFactory $factory,
    ) {
    }

    public function prePersist(LifecycleEventArgs $args): void
    {
        $this->validate($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->validate($args->getObject());
    }

    private function validate(object $entity): void
    {
        if (!$entity instanceof TransformationStep) {
            return;
        }
        // Throws ValidationFailedException or UnsupportedStepTypeException.
        $this->factory->fromStep($entity);
    }
}
