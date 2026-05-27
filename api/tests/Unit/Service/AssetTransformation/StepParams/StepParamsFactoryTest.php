<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AssetTransformation\StepParams;

use App\Entity\Asset\Asset;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\StepParams\AddBackgroundStepParams;
use App\Service\AssetTransformation\StepParams\CropStepParams;
use App\Service\AssetTransformation\StepParams\FormatConvertStepParams;
use App\Service\AssetTransformation\StepParams\RemoveBackgroundStepParams;
use App\Service\AssetTransformation\StepParams\ResizeStepParams;
use App\Service\AssetTransformation\StepParams\RotateStepParams;
use App\Service\AssetTransformation\StepParams\StepParamsFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StepParamsFactoryTest extends KernelTestCase
{
    private StepParamsFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = self::getContainer();
        // StepParamsFactory may be inlined when not referenced elsewhere yet;
        // instantiate it manually from its public dependencies (test container
        // exposes private services but cannot resurrect inlined ones).
        $this->factory = new StepParamsFactory(
            $container->get(SerializerInterface::class),
            $container->get(ValidatorInterface::class),
        );
    }

    /** Test 0bis — Asset::isPublic default false (GATING for ROUTE-08 Plan 03). */
    public function testAssetIsPublicDefaultsToFalse(): void
    {
        self::assertFalse((new Asset())->isPublic());
    }

    /** Test 1 — RESIZE happy path. */
    public function testResizeWithWidthAndModeIsValid(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::RESIZE)
            ->setParams(['width' => 800, 'mode' => 'fit']);

        $dto = $this->factory->fromStep($step);

        self::assertInstanceOf(ResizeStepParams::class, $dto);
        self::assertSame(800, $dto->width);
        self::assertSame('fit', $dto->mode);
    }

    /** Test 2 — RESIZE rejects empty (no width, no height). */
    public function testResizeWithoutWidthOrHeightFails(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::RESIZE)
            ->setParams(['mode' => 'fit']);

        $this->expectException(ValidationFailedException::class);
        $this->factory->fromStep($step);
    }

    /** Test 3 — ROTATE rejects out-of-range angle. */
    public function testRotateRejectsAngleOutOfRange(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::ROTATE)
            ->setParams(['angle' => 720]);

        $this->expectException(ValidationFailedException::class);
        $this->factory->fromStep($step);
    }

    /** Test 4 — FORMAT_CONVERT rejects unsupported format. */
    public function testFormatConvertRejectsGif(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::FORMAT_CONVERT)
            ->setParams(['format' => 'gif']);

        $this->expectException(ValidationFailedException::class);
        $this->factory->fromStep($step);
    }

    /** Test 5a — ADD_BACKGROUND type=asset ok. */
    public function testAddBackgroundAssetIsValid(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::ADD_BACKGROUND)
            ->setParams(['type' => 'asset', 'assetId' => 12]);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(AddBackgroundStepParams::class, $dto);
        self::assertSame(12, $dto->assetId);
    }

    /** Test 5b — ADD_BACKGROUND rejects unknown field `url` (SSRF guard). */
    public function testAddBackgroundRejectsUnknownUrlField(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::ADD_BACKGROUND)
            ->setParams(['type' => 'asset', 'url' => 'http://attacker.example']);

        // Either ExtraAttributesException (denormalize) or ValidationFailedException
        // depending on Symfony version — both are acceptable rejection signals.
        $this->expectException(ExtraAttributesException::class);
        $this->factory->fromStep($step);
    }

    /** Test 5c — ADD_BACKGROUND rejects ai_prompt type in Phase 3. */
    public function testAddBackgroundRejectsAiPromptInPhase3(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::ADD_BACKGROUND)
            ->setParams(['type' => 'ai_prompt']);

        $this->expectException(ValidationFailedException::class);
        $this->factory->fromStep($step);
    }

    /** Test 6a — CROP accepts xy-wh shape. */
    public function testCropXywhIsValid(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::CROP)
            ->setParams(['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100]);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(CropStepParams::class, $dto);
    }

    /** Test 6b — CROP accepts aspectRatio shape. */
    public function testCropAspectRatioIsValid(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::CROP)
            ->setParams(['aspectRatio' => '16:9', 'anchor' => 'center']);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(CropStepParams::class, $dto);
    }

    /** Test 6c — CROP rejects zero dimensions. */
    public function testCropRejectsZeroDimensions(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::CROP)
            ->setParams(['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0]);

        $this->expectException(ValidationFailedException::class);
        $this->factory->fromStep($step);
    }

    /** Test 7a — Plan 04-04: REMOVE_BACKGROUND routes to RemoveBackgroundStepParams. */
    public function testRemoveBackgroundRouting(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::REMOVE_BACKGROUND)
            ->setParams(['model' => 'birefnet', 'fallbackOnTimeout' => true]);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(RemoveBackgroundStepParams::class, $dto);
        self::assertSame('birefnet', $dto->model);
        self::assertTrue($dto->fallbackOnTimeout);
    }

    /** Test 7b — REMOVE_BACKGROUND rejects invalid model. */
    public function testRemoveBackgroundRejectsInvalidModel(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::REMOVE_BACKGROUND)
            ->setParams(['model' => 'rmbg-1.4']);

        $this->expectException(ValidationFailedException::class);
        $this->factory->fromStep($step);
    }

    /** Test 7c — REMOVE_BACKGROUND rejects unknown key (SSRF guard via strict-fields). */
    public function testRemoveBackgroundRejectsUnknownKey(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::REMOVE_BACKGROUND)
            ->setParams(['model' => 'birefnet', 'url' => 'http://evil.local']);

        $this->expectException(ExtraAttributesException::class);
        $this->factory->fromStep($step);
    }

    /** Test 7d — REMOVE_BACKGROUND defaults (empty params). */
    public function testRemoveBackgroundDefaults(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::REMOVE_BACKGROUND)
            ->setParams([]);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(RemoveBackgroundStepParams::class, $dto);
        self::assertSame('birefnet', $dto->model);
        self::assertFalse($dto->fallbackOnTimeout);
    }

    /** Bonus — FORMAT_CONVERT happy path covers Choice + quality. */
    public function testFormatConvertWebpWithQualityIsValid(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::FORMAT_CONVERT)
            ->setParams(['format' => 'webp', 'quality' => 85]);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(FormatConvertStepParams::class, $dto);
        self::assertSame(85, $dto->quality);
    }

    /** Bonus — ROTATE happy path. */
    public function testRotateAngle90IsValid(): void
    {
        $step = (new TransformationStep())
            ->setType(StepType::ROTATE)
            ->setParams(['angle' => 90]);

        $dto = $this->factory->fromStep($step);
        self::assertInstanceOf(RotateStepParams::class, $dto);
        self::assertSame(90, $dto->angle);
    }
}
