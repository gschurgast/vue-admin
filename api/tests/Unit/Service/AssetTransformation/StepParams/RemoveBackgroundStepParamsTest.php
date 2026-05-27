<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\AssetTransformation\StepParams;

use App\Service\AssetTransformation\StepParams\RemoveBackgroundStepParams;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @group phase-04
 * Plan 04-04 — RemoveBackgroundStepParams DTO (BGREMOVE-06).
 */
final class RemoveBackgroundStepParamsTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testDefaultModelIsBirefnet(): void
    {
        $dto = new RemoveBackgroundStepParams();
        self::assertSame('birefnet', $dto->model);
        self::assertFalse($dto->fallbackOnTimeout);
        self::assertCount(0, $this->validator->validate($dto));
    }

    public function testInvalidModelRejected(): void
    {
        $dto = new RemoveBackgroundStepParams(model: 'rmbg-1.4');
        $violations = $this->validator->validate($dto);
        self::assertGreaterThan(0, count($violations));
        self::assertStringContainsString('model', (string) $violations);
    }

    public function testFallbackOnTimeoutAcceptsTrue(): void
    {
        $dto = new RemoveBackgroundStepParams(model: 'birefnet', fallbackOnTimeout: true);
        self::assertTrue($dto->fallbackOnTimeout);
        self::assertCount(0, $this->validator->validate($dto));
    }

    public function testIsnetGeneralUseAccepted(): void
    {
        $dto = new RemoveBackgroundStepParams(model: 'isnet-general-use');
        self::assertCount(0, $this->validator->validate($dto));
    }
}
