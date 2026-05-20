<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ProductVariantUpdateInput;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductVariantUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductVariant
    {
        if (!$data instanceof ProductVariantUpdateInput) {
            throw new \InvalidArgumentException('Expected ProductVariantUpdateInput.');
        }

        $variant = $this->em->getRepository(ProductVariant::class)->find($data->id);
        if (!$variant) {
            throw new NotFoundHttpException(\sprintf('ProductVariant %d not found.', $data->id));
        }

        if ($data->sku !== null) {
            $variant->setSku($data->sku);
        }
        if ($data->ean !== null) {
            $variant->setEan($data->ean);
        }
        if ($data->isDefault !== null) {
            $variant->setIsDefault($data->isDefault);
        }

        $this->validator->validate($variant);
        $this->em->flush();

        return $variant;
    }
}