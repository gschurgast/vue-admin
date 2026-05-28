<?php

namespace App\Tests\Smoke;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Plan 05-02 — OPS-03 smoke test.
 *
 * Asserts the 3 Messenger transports exist (`async`, `transformations`,
 * `transformations_backfill`) and that the routing config maps each business
 * message to the correct transport. Guards against regression on the CLIP
 * `async` transport (T-05-10).
 */
final class MessengerTransportsTest extends KernelTestCase
{
    public function testAllThreeTransportsAreRegistered(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->assertTrue(
            $container->has('messenger.transport.async'),
            'Transport `async` must be declared (CLIP, intouché — T-05-10).',
        );
        $this->assertTrue(
            $container->has('messenger.transport.transformations'),
            'Transport `transformations` must be declared (warmup live — OPS-03).',
        );
        $this->assertTrue(
            $container->has('messenger.transport.transformations_backfill'),
            'Transport `transformations_backfill` must be declared (bulk/purge — OPS-03).',
        );
    }

    public function testRoutingPinsMessagesToCorrectTransport(): void
    {
        $yamlPath = __DIR__ . '/../../config/packages/messenger.yaml';
        $this->assertFileExists($yamlPath);
        $raw = (string) file_get_contents($yamlPath);

        // ComputeEmbeddingMessage MUST remain on async (T-05-10).
        $this->assertMatchesRegularExpression(
            '/App\\\\Message\\\\ComputeEmbeddingMessage:\s*async/',
            $raw,
            'ComputeEmbeddingMessage must stay routed on `async` (CLIP intouché).',
        );

        // WarmupTransformationVariantMessage routes to `transformations`.
        $this->assertMatchesRegularExpression(
            '/App\\\\Message\\\\WarmupTransformationVariantMessage:\s*transformations(?!_backfill)/',
            $raw,
            'WarmupTransformationVariantMessage must be routed on `transformations`.',
        );

        // PurgeTransformationVariantsMessage routes to `transformations_backfill`.
        $this->assertMatchesRegularExpression(
            '/App\\\\Message\\\\PurgeTransformationVariantsMessage:\s*transformations_backfill/',
            $raw,
            'PurgeTransformationVariantsMessage must remain routed on `transformations_backfill`.',
        );
    }
}
