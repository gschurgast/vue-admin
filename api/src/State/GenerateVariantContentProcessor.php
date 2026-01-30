<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GenerateVariantContentRequest;
use App\Entity\Attribute\AttributeDefinition;
use App\Entity\Product\Product;
use App\Entity\Product\ProductAttributeValue;
use App\Entity\Product\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GenerateVariantContentProcessor implements ProcessorInterface
{
    private const MODEL = 'gpt-4o-mini';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlatformInterface $platform
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GenerateVariantContentRequest
    {
        $variantId = $uriVariables['variantId'] ?? null;

        if (!$variantId) {
            throw new NotFoundHttpException('Variant ID is required');
        }

        $variant = $this->entityManager->getRepository(ProductVariant::class)->find($variantId);

        if (!$variant) {
            throw new NotFoundHttpException('Variant not found');
        }

        $product = $variant->getProduct();
        $locale = $data->locale ?? 'fr_FR';

        // Get all product attributes (inherited by variant)
        $productAttributeValues = $this->entityManager->getRepository(ProductAttributeValue::class)
            ->createQueryBuilder('pav')
            ->select('pav', 'ad', 'ao')
            ->leftJoin('pav.attributeDefinition', 'ad')
            ->leftJoin('pav.option', 'ao')
            ->where('pav.product = :productId')
            ->andWhere('pav.variant IS NULL')
            ->setParameter('productId', $product->getId())
            ->getQuery()
            ->getResult();

        // Get variant-specific attributes
        $variantAttributeValues = $this->entityManager->getRepository(ProductAttributeValue::class)
            ->createQueryBuilder('pav')
            ->select('pav', 'ad', 'ao')
            ->leftJoin('pav.attributeDefinition', 'ad')
            ->leftJoin('pav.option', 'ao')
            ->where('pav.product = :productId')
            ->andWhere('pav.variant = :variantId')
            ->setParameter('productId', $product->getId())
            ->setParameter('variantId', $variantId)
            ->getQuery()
            ->getResult();

        // Build context combining product and variant data
        $productData = $this->buildContext($product, $variant, $productAttributeValues, $variantAttributeValues);

        // Generate content
        $generatedContent = $this->generateSeoContent($productData, $locale);

        // Find or create description attribute value for this variant
        $descriptionAttrValue = $this->findOrCreateDescriptionAttribute($product, $variant, $variantAttributeValues, $locale);

        // Update the description
        $descriptionAttrValue->setValue($generatedContent);
        $this->entityManager->persist($descriptionAttrValue);
        $this->entityManager->flush();

        // Return response
        $response = new GenerateVariantContentRequest();
        $response->variantId = $variantId;
        $response->locale = $locale;
        $response->generatedContent = $generatedContent;
        $response->attributeValueId = (string) $descriptionAttrValue->getId();

        return $response;
    }

    private function buildContext(Product $product, ProductVariant $variant, array $productAttributeValues, array $variantAttributeValues): array
    {
        $context = [
            'skuRoot' => $product->getSkuRoot(),
            'variantSku' => $variant->getSku(),
            'attributes' => [],
            'imageUrl' => null,
        ];

        // Add product-level attributes
        foreach ($productAttributeValues as $attrValue) {
            $this->addAttributeToContext($attrValue, $context);
        }

        // Add/override with variant-specific attributes
        foreach ($variantAttributeValues as $attrValue) {
            $this->addAttributeToContext($attrValue, $context);
        }

        return $context;
    }

    private function addAttributeToContext(ProductAttributeValue $attrValue, array &$context): void
    {
        $definition = $attrValue->getAttributeDefinition();
        $code = $definition->getCode();
        $type = $definition->getType()->value;

        $value = null;

        if ($type === 'enum' && $attrValue->getOption()) {
            $value = $attrValue->getOption()->getCode();
        } elseif ($type === 'multienum' && $attrValue->getValues()) {
            $value = implode(', ', $attrValue->getValues());
        } else {
            $value = $attrValue->getValue();
        }

        // Skip description as we're generating it
        if ($code === 'description') {
            return;
        }

        // Track image URL separately
        if (($code === 'image' || $code === 'picture') && $type === 'media') {
            $context['imageUrl'] = $value;
        }

        if ($value !== null && $value !== '') {
            $context['attributes'][$code] = $value;
        }
    }

    private function generateSeoContent(array $productData, string $locale): string
    {
        $languageMap = [
            'fr_FR' => 'French',
            'en_US' => 'English',
            'de_DE' => 'German',
            'es_ES' => 'Spanish',
            'it_IT' => 'Italian',
            'pt_PT' => 'Portuguese',
            'nl_NL' => 'Dutch',
        ];

        $language = $languageMap[$locale] ?? 'French';

        $systemPrompt = "You are an expert e-commerce SEO copywriter. Your task is to write compelling, SEO-optimized product variant descriptions that drive conversions. Write in {$language}.";

        $attributesList = '';
        foreach ($productData['attributes'] as $code => $value) {
            $attributesList .= "- {$code}: {$value}\n";
        }

        $userPrompt = "Write a compelling SEO-optimized description for this product variant:\n\n";
        $userPrompt .= "Product SKU: {$productData['skuRoot']}\n";
        $userPrompt .= "Variant SKU: {$productData['variantSku']}\n\n";
        $userPrompt .= "Attributes:\n{$attributesList}\n";

        if ($productData['imageUrl']) {
            $userPrompt .= "Product Image URL: {$productData['imageUrl']}\n\n";
        }

        $userPrompt .= "Requirements:\n";
        $userPrompt .= "- Write a compelling description of 150-300 words\n";
        $userPrompt .= "- Include relevant keywords naturally\n";
        $userPrompt .= "- Highlight key features and benefits specific to this variant\n";
        $userPrompt .= "- Use HTML formatting (paragraphs, bold for key points)\n";
        $userPrompt .= "- Make it engaging and persuasive\n";
        $userPrompt .= "- Do not include the product name/SKU in the description\n";
        $userPrompt .= "- Write ONLY the description, no introduction or conclusion about the task";

        $messages = new MessageBag();
        $messages->add(Message::forSystem($systemPrompt));
        $messages->add(Message::ofUser($userPrompt));

        $response = $this->platform->invoke(
            model: self::MODEL,
            input: $messages
        );

        return $response->asText();
    }

    private function findOrCreateDescriptionAttribute(Product $product, ProductVariant $variant, array $variantAttributeValues, string $locale): ProductAttributeValue
    {
        // Find existing description attribute for this variant
        foreach ($variantAttributeValues as $attrValue) {
            /** @var ProductAttributeValue $attrValue */
            $definition = $attrValue->getAttributeDefinition();
            if ($definition->getCode() === 'description') {
                // Check locale match if attribute is localizable
                if ($definition->getIsLocalizable()) {
                    if ($attrValue->getLocale() === $locale) {
                        return $attrValue;
                    }
                } else {
                    return $attrValue;
                }
            }
        }

        // Find description attribute definition
        $descriptionDef = $this->entityManager->getRepository(AttributeDefinition::class)
            ->findOneBy(['code' => 'description']);

        if (!$descriptionDef) {
            throw new NotFoundHttpException('Description attribute definition not found. Please create an attribute with code "description".');
        }

        // Create new attribute value for this variant
        $newAttrValue = new ProductAttributeValue();
        $newAttrValue->setProduct($product);
        $newAttrValue->setVariant($variant);
        $newAttrValue->setAttributeDefinition($descriptionDef);

        if ($descriptionDef->getIsLocalizable()) {
            $newAttrValue->setLocale($locale);
        }

        return $newAttrValue;
    }
}