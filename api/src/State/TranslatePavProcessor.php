<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TranslatePavRequest;
use App\Entity\Product\ProductAttributeValue;
use App\Enum\Locale;
use App\Service\TranslationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Fan-out translation of a source ProductAttributeValue into every locale of
 * the Locale enum. Only fills missing locales by default; pass overwriteExisting=true
 * to refresh the target locales.
 */
class TranslatePavProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslationService $translator,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TranslatePavRequest
    {
        if (!$data instanceof TranslatePavRequest) {
            throw new BadRequestHttpException('Invalid payload');
        }

        // Fan-out translation makes up to ~16 LLM calls, comfortably above the
        // default 30s PHP limit. Lift it for this request only.
        @set_time_limit(0);

        $sourceId = $data->sourceAttributeValueId ?? $this->extractIdFromIri($data->sourceAttributeValue);
        if (!$sourceId) {
            throw new BadRequestHttpException('sourceAttributeValue (IRI) or sourceAttributeValueId is required');
        }

        /** @var ProductAttributeValue|null $source */
        $source = $this->entityManager->getRepository(ProductAttributeValue::class)->find($sourceId);
        if (!$source) {
            throw new NotFoundHttpException('Source attribute value not found');
        }

        $definition = $source->getAttributeDefinition();
        if (!$definition || !$definition->getIsLocalizable()) {
            throw new BadRequestHttpException('Source attribute is not localizable');
        }

        $sourceText = $source->getValue();
        if ($sourceText === null || $sourceText === '') {
            throw new BadRequestHttpException('Source attribute value is empty — nothing to translate');
        }

        $sourceLocaleCode = $source->getLocale();
        if (!$sourceLocaleCode) {
            throw new BadRequestHttpException('Source attribute value has no locale');
        }
        $sourceLocale = Locale::tryFrom($sourceLocaleCode);
        if (!$sourceLocale) {
            throw new BadRequestHttpException('Source locale is not supported: ' . $sourceLocaleCode);
        }

        // Lookup existing translations for the same product/variant/attribute.
        $repo = $this->entityManager->getRepository(ProductAttributeValue::class);
        $qb = $repo->createQueryBuilder('pav')
            ->where('pav.product = :product')
            ->andWhere('pav.attributeDefinition = :def')
            ->setParameter('product', $source->getProduct())
            ->setParameter('def', $definition);

        if ($source->getVariant()) {
            $qb->andWhere('pav.variant = :variant')->setParameter('variant', $source->getVariant());
        } else {
            $qb->andWhere('pav.variant IS NULL');
        }

        /** @var ProductAttributeValue[] $existing */
        $existing = $qb->getQuery()->getResult();
        $byLocale = [];
        foreach ($existing as $pav) {
            if ($pav->getLocale()) {
                $byLocale[$pav->getLocale()] = $pav;
            }
        }

        $response = new TranslatePavRequest();
        $response->sourceAttributeValueId = $sourceId;

        foreach (Locale::cases() as $targetLocale) {
            if ($targetLocale === $sourceLocale) {
                continue;
            }
            $targetCode = $targetLocale->value;

            if (isset($byLocale[$targetCode])) {
                if (!$data->overwriteExisting) {
                    $response->skippedCount++;
                    $response->skippedLocales[] = $targetCode;
                    continue;
                }
                $target = $byLocale[$targetCode];
            } else {
                $target = new ProductAttributeValue();
                $target->setProduct($source->getProduct());
                $target->setAttributeDefinition($definition);
                if ($source->getVariant()) {
                    $target->setVariant($source->getVariant());
                }
                $target->setLocale($targetCode);
            }

            $translated = $this->translator->translateToSingle($sourceText, $sourceLocale, $targetLocale);
            $target->setValue($translated);

            $this->entityManager->persist($target);
            $response->createdCount++;
            $response->createdLocales[] = $targetCode;
        }

        $this->entityManager->flush();

        return $response;
    }

    private function extractIdFromIri(?string $iri): ?int
    {
        if (!$iri) {
            return null;
        }
        if (preg_match('#/(\d+)$#', $iri, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}
