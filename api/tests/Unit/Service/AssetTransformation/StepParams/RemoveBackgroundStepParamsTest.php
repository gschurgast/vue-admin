<?php

namespace App\Tests\Unit\Service\AssetTransformation\StepParams;

use PHPUnit\Framework\TestCase;

/**
 * @group phase-04
 * Stubs created in Plan 04-01 Wave 0. Plan 04-04 implements RemoveBackgroundStepParams.
 */
final class RemoveBackgroundStepParamsTest extends TestCase
{
    public function testDefaultModelIsBirefnet(): void
    {
        $this->markTestSkipped('Plan 04-04 will implement RemoveBackgroundStepParams.');
    }

    public function testInvalidModelRejected(): void
    {
        $this->markTestSkipped('Plan 04-04 will implement RemoveBackgroundStepParams.');
    }

    public function testFallbackOnTimeoutDefaultsToFalse(): void
    {
        $this->markTestSkipped('Plan 04-04 will implement RemoveBackgroundStepParams.');
    }
}
