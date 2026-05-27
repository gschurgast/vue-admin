<?php

namespace App\Tests\Unit\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use App\Service\AssetTransformation\StepHandler\RemoveBackgroundHandler;
use App\Service\AssetTransformation\TransformationPipelineException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group phase-04
 * Plan 04-04 — RemoveBackgroundHandler unit tests (BGREMOVE-06).
 */
final class RemoveBackgroundHandlerTest extends TestCase
{
    public function testHandlerPostsToRemoveBackgroundEndpoint(): void
    {
        $requestedUrl = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$requestedUrl): MockResponse {
            $requestedUrl = "$method $url";
            return new MockResponse('PNG-bytes-here', [
                'http_code' => 200,
                'response_headers' => [
                    'content-type' => 'image/png',
                    'x-render-duration-ms' => '1850',
                    'x-model-used' => 'birefnet',
                ],
            ]);
        }, 'http://embedder:8000');

        $handler = new RemoveBackgroundHandler($client, 6000);
        $result = $handler->run('FAKE-INPUT', ['model' => 'birefnet'], 6000);

        self::assertSame('PNG-bytes-here', $result->bytes);
        self::assertSame('image/png', $result->contentType);
        self::assertSame(1850, $result->renderMs);
        self::assertStringContainsString('POST http://embedder:8000/img/remove-background', $requestedUrl);
    }

    public function testSupportedTypeIsRemoveBackground(): void
    {
        self::assertSame(StepType::REMOVE_BACKGROUND, RemoveBackgroundHandler::supportedType());
    }

    public function testDefaultTimeoutMsReflectsConstructorInjection(): void
    {
        $client = new MockHttpClient([], 'http://embedder:8000');
        $handler = new RemoveBackgroundHandler($client, 6000);
        self::assertSame(6000, $handler->defaultTimeoutMs());
    }

    public function testHandlerWraps504AsPipelineException(): void
    {
        // The retry strategy lives on embedder.client (services.yaml). With a
        // bare MockHttpClient no retry happens — assert 504 propagates as
        // TransformationPipelineException (not silently swallowed) and that
        // exactly 1 call was made at this unit level.
        $callCount = 0;
        $client = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;
            return new MockResponse('timeout', [
                'http_code' => 504,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        }, 'http://embedder:8000');

        $handler = new RemoveBackgroundHandler($client, 6000);

        try {
            $handler->run('FAKE-INPUT', ['model' => 'birefnet'], 6000);
            self::fail('Expected TransformationPipelineException');
        } catch (TransformationPipelineException $e) {
            self::assertStringContainsString('504', $e->getMessage());
            self::assertSame(1, $callCount, 'No retry should happen at unit-level handler');
        }
    }
}
