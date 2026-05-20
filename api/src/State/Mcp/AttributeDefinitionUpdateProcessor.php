<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\AttributeDefinitionUpdateInput;
use App\Entity\Attribute\AttributeDefinition;
use App\Enum\AttributeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AttributeDefinitionUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AttributeDefinition
    {
        if (!$data instanceof AttributeDefinitionUpdateInput) {
            throw new \InvalidArgumentException('Expected AttributeDefinitionUpdateInput.');
        }

        $definition = $this->em->getRepository(AttributeDefinition::class)->find($data->id);
        if (!$definition) {
            throw new NotFoundHttpException(\sprintf('AttributeDefinition %d not found.', $data->id));
        }

        if ($data->code !== null) {
            $definition->setCode($data->code);
        }
        if ($data->type !== null) {
            $definition->setType(AttributeType::from($data->type));
        }
        if ($data->isLocalizable !== null) {
            $definition->setIsLocalizable($data->isLocalizable);
        }
        if ($data->isScopable !== null) {
            $definition->setIsScopable($data->isScopable);
        }
        if ($data->isRequired !== null) {
            $definition->setIsRequired($data->isRequired);
        }
        if ($data->defaultValue !== null) {
            $definition->setDefaultValue($data->defaultValue);
        }
        if ($data->helpText !== null) {
            $definition->setHelpText($data->helpText);
        }
        if ($data->unit !== null) {
            $definition->setUnit($data->unit);
        }
        if ($data->relationEndpoint !== null) {
            $definition->setRelationEndpoint($data->relationEndpoint);
        }
        if ($data->validationRules !== null) {
            $definition->setValidationRules($data->validationRules);
        }
        if ($data->allowedValues !== null) {
            $definition->setAllowedValues($data->allowedValues);
        }
        if ($data->sortOrder !== null) {
            $definition->setSortOrder($data->sortOrder);
        }

        $this->validator->validate($definition);
        $this->em->flush();

        return $definition;
    }
}