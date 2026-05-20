<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ProductAttributeValueUpdateInput;
use App\Entity\Attribute\AttributeOption;
use App\Entity\Product\ProductAttributeValue;
use App\Enum\Market;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductAttributeValueUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductAttributeValue
    {
        if (!$data instanceof ProductAttributeValueUpdateInput) {
            throw new \InvalidArgumentException('Expected ProductAttributeValueUpdateInput.');
        }

        $value = $this->em->getRepository(ProductAttributeValue::class)->find($data->id);
        if (!$value) {
            throw new NotFoundHttpException(\sprintf('ProductAttributeValue %d not found.', $data->id));
        }

        if ($data->optionId !== null) {
            $option = $this->em->getRepository(AttributeOption::class)->find($data->optionId);
            if (!$option) {
                throw new NotFoundHttpException(\sprintf('AttributeOption %d not found.', $data->optionId));
            }
            $value->setOption($option);
        }
        if ($data->value !== null) {
            $value->setValue($data->value);
        }
        if ($data->values !== null) {
            $value->setValues($data->values);
        }
        if ($data->locale !== null) {
            $value->setLocale($data->locale);
        }
        if ($data->market !== null) {
            $value->setMarket(Market::from($data->market));
        }

        $this->validator->validate($value);
        $this->em->flush();

        return $value;
    }
}