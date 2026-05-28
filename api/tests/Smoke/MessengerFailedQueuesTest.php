<?php

namespace App\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Plan 05-02 — OPS-04 smoke test.
 *
 * Asserts each of the 3 transports declares its own dedicated `failure_transport`
 * (no shared `failed` bucket) and that the 3 failed transports are configured
 * with distinct Redis DSNs.
 */
final class MessengerFailedQueuesTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $config;

    public static function setUpBeforeClass(): void
    {
        $yamlPath = __DIR__ . '/../../config/packages/messenger.yaml';
        self::$config = Yaml::parseFile($yamlPath);
    }

    public function testEachTransportDeclaresIsOwnFailureTransport(): void
    {
        $transports = self::$config['framework']['messenger']['transports'] ?? [];

        $expected = [
            'async' => 'async_failed',
            'transformations' => 'transformations_failed',
            'transformations_backfill' => 'transformations_backfill_failed',
        ];

        foreach ($expected as $transport => $expectedFailure) {
            $this->assertArrayHasKey($transport, $transports, "Transport `$transport` missing.");
            $node = $transports[$transport];
            $this->assertIsArray($node, "Transport `$transport` must be a full config block (not a shorthand DSN).");
            $this->assertSame(
                $expectedFailure,
                $node['failure_transport'] ?? null,
                "Transport `$transport` must declare failure_transport=`$expectedFailure`.",
            );
        }
    }

    public function testThreeFailedTransportsHaveDistinctDsns(): void
    {
        $transports = self::$config['framework']['messenger']['transports'] ?? [];

        $failedNames = ['async_failed', 'transformations_failed', 'transformations_backfill_failed'];
        $dsns = [];
        foreach ($failedNames as $name) {
            $this->assertArrayHasKey($name, $transports, "Failed transport `$name` missing.");
            $entry = $transports[$name];
            $dsn = is_array($entry) ? ($entry['dsn'] ?? null) : $entry;
            $this->assertIsString($dsn, "Failed transport `$name` must declare a DSN.");
            $this->assertNotContains($dsn, $dsns, "Failed transport `$name` DSN must be distinct from others.");
            $dsns[] = $dsn;
        }
    }
}
