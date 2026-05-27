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
 * Phase 4 (D-16): REMOVE_BACKGROUND is now served sync via the BiRefNet
 * endpoint (Plan 04-02 + Plan 04-04). Only ADD_BACKGROUND with
 * `type=ai_prompt` (Stable Diffusion, Phase 5+) still requires the async
 * 202+Location path and is therefore rejected with 404 here.
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

        // D-05 + Phase 4 D-16 — sync-only AI gating. Phase 4 introduces a sync
        // remove_background handler (BiRefNet/isnet, < 8s wall-clock); only the
        // SD-backed `add_background type:ai_prompt` still requires the async path.
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
        // Phase 4 (D-16): REMOVE_BACKGROUND is now sync (handled by Plan 04-02/04-04).
        if ($step->getType() === StepType::ADD_BACKGROUND) {
            $params = $step->getParams();
            return ($params['type'] ?? null) === 'ai_prompt';
        }
        return false;
    }
}
