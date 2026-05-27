<?php

namespace App\Tests\Unit\Service\AssetTransformation;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\AssetType;
use App\Enum\StepType;
use App\Service\AssetTransformation\TransformationLookup;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Unit tests for TransformationLookup (Plan 03-03 Task 1).
 *
 * Strict invariant verified across all 5 rejection branches: every failure
 * raises NotFoundHttpException — NEVER AccessDeniedException (D-10, T-03-11).
 * The route must never disclose whether code/asset exists.
 */
#[IgnoreDeprecations]
final class TransformationLookupTest extends TestCase
{
    public function testUnknownCodeRaisesNotFound(): void
    {
        $lookup = $this->makeLookup(tx: null, asset: null);

        $thrown = $this->capture(fn () => $lookup->findOr404('nope', 42));
        $this->assertInstanceOf(NotFoundHttpException::class, $thrown);
        $this->assertNotInstanceOf(AccessDeniedException::class, $thrown);
    }

    public function testMissingAssetRaisesNotFound(): void
    {
        $tx = $this->makeTx(code: 'thumb-200');
        $lookup = $this->makeLookup(tx: $tx, asset: null);

        $thrown = $this->capture(fn () => $lookup->findOr404('thumb-200', 999));
        $this->assertInstanceOf(NotFoundHttpException::class, $thrown);
    }

    public function testPrivateAssetRaisesNotFoundNotForbidden(): void
    {
        $tx = $this->makeTx(code: 'thumb-200');
        $asset = $this->makeAsset(isPublic: false);
        $lookup = $this->makeLookup(tx: $tx, asset: $asset);

        $thrown = $this->capture(fn () => $lookup->findOr404('thumb-200', 1));
        $this->assertInstanceOf(NotFoundHttpException::class, $thrown);
        $this->assertNotInstanceOf(AccessDeniedException::class, $thrown);
    }

    public function testRemoveBackgroundStepRaisesNotFound(): void
    {
        $tx = $this->makeTx(code: 'rmbg', steps: [
            $this->makeStep(StepType::REMOVE_BACKGROUND, []),
        ]);
        $asset = $this->makeAsset(isPublic: true);
        $lookup = $this->makeLookup(tx: $tx, asset: $asset);

        $thrown = $this->capture(fn () => $lookup->findOr404('rmbg', 1));
        $this->assertInstanceOf(NotFoundHttpException::class, $thrown);
    }

    public function testAddBackgroundAiPromptRaisesNotFound(): void
    {
        $tx = $this->makeTx(code: 'ai-bg', steps: [
            $this->makeStep(StepType::ADD_BACKGROUND, ['type' => 'ai_prompt', 'prompt' => 'studio']),
        ]);
        $asset = $this->makeAsset(isPublic: true);
        $lookup = $this->makeLookup(tx: $tx, asset: $asset);

        $thrown = $this->capture(fn () => $lookup->findOr404('ai-bg', 1));
        $this->assertInstanceOf(NotFoundHttpException::class, $thrown);
    }

    public function testNominalReturnsTxAndAsset(): void
    {
        $tx = $this->makeTx(code: 'thumb-200', steps: [
            $this->makeStep(StepType::RESIZE, ['width' => 200]),
        ]);
        $asset = $this->makeAsset(isPublic: true);
        $lookup = $this->makeLookup(tx: $tx, asset: $asset);

        [$resolvedTx, $resolvedAsset] = $lookup->findOr404('thumb-200', 1);
        $this->assertSame($tx, $resolvedTx);
        $this->assertSame($asset, $resolvedAsset);
    }

    public function testAddBackgroundColorIsAllowed(): void
    {
        // Color/asset variants of ADD_BACKGROUND are sync-safe (Phase 2
        // /img/add-background). Only `type=ai_prompt` is gated by D-05.
        $tx = $this->makeTx(code: 'white-bg', steps: [
            $this->makeStep(StepType::ADD_BACKGROUND, ['type' => 'color', 'color' => '#ffffff']),
        ]);
        $asset = $this->makeAsset(isPublic: true);
        $lookup = $this->makeLookup(tx: $tx, asset: $asset);

        [, $resolvedAsset] = $lookup->findOr404('white-bg', 1);
        $this->assertSame($asset, $resolvedAsset);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param TransformationStep[] $steps
     */
    private function makeTx(string $code, array $steps = []): AssetTransformation
    {
        $tx = new AssetTransformation();
        $tx->setCode($code);
        $tx->setLabel('Test');
        foreach ($steps as $step) {
            $tx->addStep($step);
        }
        return $tx;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function makeStep(StepType $type, array $params): TransformationStep
    {
        $step = new TransformationStep();
        $step->setType($type);
        $step->setParams($params);
        $step->setPosition(0);
        return $step;
    }

    private function makeAsset(bool $isPublic): Asset
    {
        $asset = new Asset();
        $asset->setType(AssetType::IMAGE);
        $asset->setMimeType('image/png');
        $asset->setFilename('a.png');
        $asset->setIsPublic($isPublic);
        return $asset;
    }

    private function makeLookup(?AssetTransformation $tx, ?Asset $asset): TransformationLookup
    {
        // Use createStub() (no expects), no deprecation noise. We do not assert
        // call counts here — only behaviour.
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($tx);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('find')->willReturn($asset);

        return new TransformationLookup($em);
    }

    /**
     * Capture a thrown exception from a closure so we can assert on the
     * *concrete* class (verifies it's not AccessDeniedException either).
     */
    private function capture(\Closure $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('Expected exception, none thrown');
    }
}
