<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ProductAttributeValueCreateInput;
use App\Entity\Attribute\AttributeDefinition;
use App\Entity\Attribute\AttributeOption;
use App\Entity\Product\Product;
use App\Entity\Product\ProductAttributeValue;
use App\Entity\Product\ProductVariant;
use App\Enum\Market;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductAttributeValueCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductAttributeValue
    {
        if (!$data instanceof ProductAttributeValueCreateInput) {
            throw new \InvalidArgumentException('Expected ProductAttributeValueCreateInput.');
        }

        $product = $this->em->getRepository(Product::class)->find($data->productId);
        if (!$product) {
            throw new NotFoundHttpException(\sprintf('Product %d not found.', $data->productId));
        }

        $definition = $this->em->getRepository(AttributeDefinition::class)->find($data->attributeDefinitionId);
        if (!$definition) {
            throw new NotFoundHttpException(\sprintf('AttributeDefinition %d not found.', $data->attributeDefinitionId));
        }

        $value = new ProductAttributeValue();
        $value->setProduct($product);
        $value->setAttributeDefinition($definition);

        if ($data->variantId !== null) {
            $variant = $this->em->getRepository(ProductVariant::class)->find($data->variantId);
            if (!$variant) {
                throw new NotFoundHttpException(\sprintf('ProductVariant %d not found.', $data->variantId));
            }
            $value->setVariant($variant);
        }

        if ($data->optionId !== null) {
            $option = $this->em->getRepository(AttributeOption::class)->find($data->optionId);
            if (!$option) {
                throw new NotFoundHttpException(\sprintf('AttributeOption %d not found.', $data->optionId));
            }
            $value->setOption($option);
        }

        $value->setValue($data->value);
        $value->setValues($data->values);
        $value->setLocale($data->locale);
        $value->setMarket($data->market !== null ? Market::from($data->market) : null);

        $this->validator->validate($value);
        $this->em->persist($value);
        $this->em->flush();

        return $value;
    }
}