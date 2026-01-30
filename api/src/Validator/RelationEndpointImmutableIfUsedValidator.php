<?php

namespace App\Validator;

use App\Entity\Attribute\AttributeDefinition;
use App\Entity\Product\ProductAttributeValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class RelationEndpointImmutableIfUsedValidator extends ConstraintValidator
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof RelationEndpointImmutableIfUsed) {
            throw new UnexpectedTypeException($constraint, RelationEndpointImmutableIfUsed::class);
        }

        if (!$value instanceof AttributeDefinition) {
            throw new UnexpectedValueException($value, AttributeDefinition::class);
        }

        // Skip validation for new entities
        if ($value->getId() === null) {
            return;
        }

        // Get the original data from the unit of work
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $originalData = $unitOfWork->getOriginalEntityData($value);

        // If no original data, this is a new entity
        if (empty($originalData)) {
            return;
        }

        $originalEndpoint = $originalData['relationEndpoint'] ?? null;
        $newEndpoint = $value->getRelationEndpoint();

        // If endpoint hasn't changed, no need to validate
        if ($originalEndpoint === $newEndpoint) {
            return;
        }

        // Check if there are any ProductAttributeValue entries with a value for this definition
        $repository = $this->entityManager->getRepository(ProductAttributeValue::class);
        $count = $repository->createQueryBuilder('pav')
            ->select('COUNT(pav.id)')
            ->where('pav.attributeDefinition = :definition')
            ->andWhere('pav.value IS NOT NULL')
            ->setParameter('definition', $value)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count > 0) {
            $this->context->buildViolation($constraint->message)
                ->atPath('relationEndpoint')
                ->addViolation();
        }
    }
}