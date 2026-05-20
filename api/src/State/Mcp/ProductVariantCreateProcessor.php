<?php

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\ProductVariantCreateInput;
use App\Entity\Product\Product;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductVariantCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductVariant
    {
        if (!$data instanceof ProductVariantCreateInput) {
            throw new \InvalidArgumentException('Expected ProductVariantCreateInput.');
        }

        $product = $this->em->getRepository(Product::class)->find($data->productId);
        if (!$product) {
            throw new NotFoundHttpException(\sprintf('Product %d not found.', $data->productId));
        }

        $variant = new ProductVariant();
        $variant->setProduct($product);
        $variant->setSku($data->sku);
        $variant->setEan($data->ean);
        $variant->setIsDefault($data->isDefault);

        $this->validator->validate($variant);
        $this->em->persist($variant);
        $this->em->flush();

        return $variant;
    }
}