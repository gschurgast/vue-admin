<?php

namespace App\Tests\Unit\Service\AssetTransformation\StepHandler;

use PHPUnit\Framework\TestCase;

/**
 * @group phase-04
 * Stubs created in Plan 04-01 Wave 0. Plan 04-04 implements RemoveBackgroundHandler.
 */
final class RemoveBackgroundHandlerTest extends TestCase
{
    public function testHandlerPostsToRemoveBackgroundEndpoint(): void
    {
        $this->markTestSkipped('Plan 04-04 will implement RemoveBackgroundHandler.');
    }

    public function testHandlerReturnsHandlerResultWithPngContentType(): void
    {
        $this->markTestSkipped('Plan 04-04 will implement RemoveBackgroundHandler.');
    }

    public function testHandlerDoesNotRetryOn504(): void
    {
        $this->markTestSkipped('Plan 04-04 will implement RemoveBackgroundHandler.');
    }
}
