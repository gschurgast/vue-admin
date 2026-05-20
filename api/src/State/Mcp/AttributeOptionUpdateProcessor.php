<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\AttributeOptionUpdateInput;
use App\Entity\Attribute\AttributeOption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AttributeOptionUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AttributeOption
    {
        if (!$data instanceof AttributeOptionUpdateInput) {
            throw new \InvalidArgumentException('Expected AttributeOptionUpdateInput.');
        }

        $option = $this->em->getRepository(AttributeOption::class)->find($data->id);
        if (!$option) {
            throw new NotFoundHttpException(\sprintf('AttributeOption %d not found.', $data->id));
        }

        if ($data->code !== null) {
            $option->setCode($data->code);
        }
        if ($data->sortOrder !== null) {
            $option->setSortOrder($data->sortOrder);
        }

        $this->validator->validate($option);
        $this->em->flush();

        return $option;
    }
}