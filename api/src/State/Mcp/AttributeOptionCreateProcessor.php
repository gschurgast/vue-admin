<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\AttributeOptionCreateInput;
use App\Entity\Attribute\AttributeDefinition;
use App\Entity\Attribute\AttributeOption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AttributeOptionCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AttributeOption
    {
        if (!$data instanceof AttributeOptionCreateInput) {
            throw new \InvalidArgumentException('Expected AttributeOptionCreateInput.');
        }

        $definition = $this->em->getRepository(AttributeDefinition::class)->find($data->attributeDefinitionId);
        if (!$definition) {
            throw new NotFoundHttpException(\sprintf('AttributeDefinition %d not found.', $data->attributeDefinitionId));
        }

        $option = new AttributeOption();
        $option->setAttribute($definition);
        $option->setCode($data->code);
        $option->setSortOrder($data->sortOrder);

        $this->validator->validate($option);
        $this->em->persist($option);
        $this->em->flush();

        return $option;
    }
}