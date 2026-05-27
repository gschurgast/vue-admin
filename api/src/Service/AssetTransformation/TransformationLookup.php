<?php

namespace App\Service\AssetTransformation;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves a `(code, assetId)` pair to a `(transformation, asset)` tuple for
 * the public route (Plan 03-03).
 *
 * **Every rejection raises {@see NotFoundHttpException}** — never
 * AccessDeniedException (D-10, T-03-11): we leak no information about whether
 * the code/asset exists.
 *
 * Phase 3 is sync-only: transformations containing an AI step
 * (REMOVE_BACKGROUND or ADD_BACKGROUND with type=ai_prompt) are also rejected
 * with 404 (D-05). The async 202+Location path is introduced in Phase 5.
 */
class TransformationLookup
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @return array{0: AssetTransformation, 1: Asset}
     *
     * @throws NotFoundHttpException whenever the route should answer 404
     */
    public function findOr404(string $code, int $assetId): array
    {
        $tx = $this->em->getRepository(AssetTransformation::class)->findOneBy(['code' => $code]);
        if (!$tx instanceof AssetTransformation) {
            throw new NotFoundHttpException();
        }

        // D-05 — sync-only AI gating. Phase 3 cannot serve transformations
        // that require the async 202+Location flow.
        foreach ($tx->getSteps() as $step) {
            if ($this->isAsyncStep($step)) {
                throw new NotFoundHttpException();
            }
        }

        $asset = $this->em->find(Asset::class, $assetId);
        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException();
        }

        // ROUTE-08 — strict isPublic gate, default false. T-03-11 (IDOR) mitigated.
        if (!$asset->isPublic()) {
            throw new NotFoundHttpException();
        }

        return [$tx, $asset];
    }

    private function isAsyncStep(TransformationStep $step): bool
    {
        $type = $step->getType();
        if ($type === StepType::REMOVE_BACKGROUND) {
            return true;
        }
        if ($type === StepType::ADD_BACKGROUND) {
            $params = $step->getParams();
            return ($params['type'] ?? null) === 'ai_prompt';
        }
        return false;
    }
}
