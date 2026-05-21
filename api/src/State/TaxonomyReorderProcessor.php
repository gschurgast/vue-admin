<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TaxonomyReorderRequest;
use App\Entity\Taxonomy\Taxonomy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TaxonomyReorderProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TaxonomyReorderRequest
    {
        if (!$data instanceof TaxonomyReorderRequest) {
            throw new \InvalidArgumentException('Expected TaxonomyReorderRequest');
        }

        if (!\is_array($data->items) || $data->items === []) {
            $data->updated = 0;
            return $data;
        }

        $ids = [];
        foreach ($data->items as $item) {
            if (!isset($item['id']) || !\is_int($item['id'])) {
                throw new BadRequestHttpException('Each item must have an integer id');
            }
            $ids[] = $item['id'];
        }

        /** @var Taxonomy[] $entities */
        $entities = $this->em->getRepository(Taxonomy::class)->findBy(['id' => $ids]);
        $byId = [];
        foreach ($entities as $e) {
            $byId[$e->getId()] = $e;
        }

        // Build a desired-parent map first so we can check cycles globally.
        $desiredParent = [];
        foreach ($data->items as $item) {
            $id = $item['id'];
            if (!isset($byId[$id])) {
                throw new BadRequestHttpException(sprintf('Taxonomy #%d not found', $id));
            }
            $parentId = null;
            if (\array_key_exists('parent', $item) && $item['parent'] !== null && $item['parent'] !== '') {
                $parentId = $this->extractId((string) $item['parent']);
                if ($parentId === null) {
                    throw new BadRequestHttpException(sprintf('Invalid parent IRI: %s', $item['parent']));
                }
                if (!isset($byId[$parentId])) {
                    // Parent may be outside the payload — fetch it.
                    $parentEntity = $this->em->getRepository(Taxonomy::class)->find($parentId);
                    if (!$parentEntity) {
                        throw new BadRequestHttpException(sprintf('Parent taxonomy #%d not found', $parentId));
                    }
                    $byId[$parentId] = $parentEntity;
                }
            }
            $desiredParent[$id] = $parentId;
        }

        // Cycle detection against the merged graph (existing + desired changes).
        $this->assertNoCycles($desiredParent, $byId);

        $updated = 0;
        foreach ($data->items as $item) {
            $entity = $byId[$item['id']];
            $newParent = $desiredParent[$item['id']] !== null ? $byId[$desiredParent[$item['id']]] : null;

            if ($entity->getParent() !== $newParent) {
                $entity->setParent($newParent);
                $updated++;
            }

            if (isset($item['position']) && \is_int($item['position'])) {
                if ($entity->getPosition() !== $item['position']) {
                    $entity->setPosition($item['position']);
                    $updated++;
                }
            }
        }

        $this->em->flush();
        $data->updated = $updated;

        return $data;
    }

    private function extractId(string $iri): ?int
    {
        if (preg_match('#/(\d+)$#', $iri, $m)) {
            return (int) $m[1];
        }
        if (ctype_digit($iri)) {
            return (int) $iri;
        }
        return null;
    }

    /**
     * @param array<int, ?int> $desiredParent  id => parentId|null (only for items being moved)
     * @param array<int, Taxonomy> $byId       loaded entities (includes parents)
     */
    private function assertNoCycles(array $desiredParent, array $byId): void
    {
        $resolveParent = function (int $id) use ($desiredParent, $byId): ?int {
            if (\array_key_exists($id, $desiredParent)) {
                return $desiredParent[$id];
            }
            $entity = $byId[$id] ?? null;
            if (!$entity) {
                $entity = $this->em->getRepository(Taxonomy::class)->find($id);
            }
            return $entity?->getParent()?->getId();
        };

        foreach ($desiredParent as $id => $_parentId) {
            $seen = [];
            $cursor = $id;
            while ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    throw new BadRequestHttpException(sprintf('Reorder would create a cycle involving taxonomy #%d', $id));
                }
                $seen[$cursor] = true;
                $cursor = $resolveParent($cursor);
                if ($cursor === $id && $cursor !== null) {
                    // re-check at next loop iteration via $seen
                }
            }
        }
    }
}
