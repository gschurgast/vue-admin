<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ProductAttributeValuesRequest;
use App\Entity\Product\ProductAttributeValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\SerializerInterface;

class ProductAttributeValuesProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private SerializerInterface $serializer
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductAttributeValuesRequest
    {
        $productId = $uriVariables['productId'] ?? null;
        $request = $this->requestStack->getCurrentRequest();
        $variantId = $request?->query->get('variantId');
        $locale = $request?->query->get('locale');

        $response = new ProductAttributeValuesRequest();
        $response->productId = $productId ? (int) $productId : null;
        $response->variantId = $variantId ? (int) $variantId : null;

        if (!$productId) {
            return $response;
        }

        $repository = $this->entityManager->getRepository(ProductAttributeValue::class);

        // Get product-level attributes (variant is null).
        // When a locale is specified, keep non-localizable PAVs (locale IS NULL)
        // plus localizable PAVs whose locale matches the selection.
        $qb = $repository->createQueryBuilder('pav')
            ->select('pav', 'ad', 'ao')
            ->leftJoin('pav.attributeDefinition', 'ad')
            ->leftJoin('pav.option', 'ao')
            ->where('pav.product = :productId')
            ->andWhere('pav.variant IS NULL')
            ->setParameter('productId', $productId);

        if ($locale) {
            $qb->andWhere('(ad.isLocalizable = false AND pav.locale IS NULL) OR (ad.isLocalizable = true AND pav.locale = :locale)')
               ->setParameter('locale', $locale);
        }

        $productAttributes = $qb->getQuery()->getResult();

        $response->productAttributes = $this->normalizeAttributeValues($productAttributes);

        // Get variant-level attributes if variantId is provided
        if ($variantId) {
            $vqb = $repository->createQueryBuilder('pav')
                ->select('pav', 'ad', 'ao')
                ->leftJoin('pav.attributeDefinition', 'ad')
                ->leftJoin('pav.option', 'ao')
                ->where('pav.product = :productId')
                ->andWhere('pav.variant = :variantId')
                ->setParameter('productId', $productId)
                ->setParameter('variantId', $variantId);

            if ($locale) {
                $vqb->andWhere('(ad.isLocalizable = false AND pav.locale IS NULL) OR (ad.isLocalizable = true AND pav.locale = :locale)')
                    ->setParameter('locale', $locale);
            }

            $response->variantAttributes = $this->normalizeAttributeValues($vqb->getQuery()->getResult());
        }

        return $response;
    }

    /**
     * @param ProductAttributeValue[] $attributeValues
     * @return array<mixed>
     */
    private function normalizeAttributeValues(array $attributeValues): array
    {
        return array_map(function (ProductAttributeValue $pav) {
            $definition = $pav->getAttributeDefinition();
            $option = $pav->getOption();

            // Normalize values array (for multienum) to include @id
            $values = $pav->getValues();
            $normalizedValues = null;
            if (is_array($values)) {
                $normalizedValues = $values;
            }

            return [
                '@id' => '/api/product_attribute_values/' . $pav->getId(),
                'id' => $pav->getId(),
                'value' => $pav->getValue(),
                'values' => $normalizedValues,
                'locale' => $pav->getLocale(),
                'market' => $pav->getMarket()?->value,
                'attributeDefinition' => [
                    '@id' => '/api/attribute_definitions/' . $definition->getId(),
                    'id' => $definition->getId(),
                    'code' => $definition->getCode(),
                    'type' => $definition->getType()->value,
                    'unit' => $definition->getUnit(),
                    'relationEndpoint' => $definition->getRelationEndpoint(),
                    'isRequired' => $definition->getIsRequired(),
                    'isLocalizable' => $definition->getIsLocalizable(),
                    'isScopable' => $definition->getIsScopable(),
                    'validationRules' => $definition->getValidationRules(),
                    'defaultValue' => $definition->getDefaultValue(),
                    'helpText' => $definition->getHelpText(),
                ],
                'option' => $option ? [
                    '@id' => '/api/attribute_options/' . $option->getId(),
                    'id' => $option->getId(),
                    'code' => $option->getCode(),
                ] : null,
            ];
        }, $attributeValues);
    }
}
