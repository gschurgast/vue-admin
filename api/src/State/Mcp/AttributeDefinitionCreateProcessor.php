<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\AttributeDefinitionCreateInput;
use App\Entity\Attribute\AttributeDefinition;
use App\Enum\AttributeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AttributeDefinitionCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AttributeDefinition
    {
        if (!$data instanceof AttributeDefinitionCreateInput) {
            throw new \InvalidArgumentException('Expected AttributeDefinitionCreateInput.');
        }

        $definition = new AttributeDefinition();
        $definition->setCode($data->code);
        $definition->setType(AttributeType::from($data->type));
        $definition->setIsLocalizable($data->isLocalizable);
        $definition->setIsScopable($data->isScopable);
        $definition->setIsRequired($data->isRequired);
        $definition->setDefaultValue($data->defaultValue);
        $definition->setHelpText($data->helpText);
        $definition->setUnit($data->unit);
        $definition->setRelationEndpoint($data->relationEndpoint);
        $definition->setValidationRules($data->validationRules);
        $definition->setAllowedValues($data->allowedValues);
        $definition->setSortOrder($data->sortOrder);

        $this->validator->validate($definition);
        $this->em->persist($definition);
        $this->em->flush();

        return $definition;
    }
}