<?php

namespace App\Tests\Unit\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use App\Service\AssetTransformation\StepHandler\AddBackgroundStepHandler;
use App\Service\AssetTransformation\StepHandler\CropStepHandler;
use App\Service\AssetTransformation\StepHandler\FormatConvertStepHandler;
use App\Service\AssetTransformation\StepHandler\HandlerResult;
use App\Service\AssetTransformation\StepHandler\ResizeStepHandler;
use App\Service\AssetTransformation\StepHandler\RotateStepHandler;
use App\Service\AssetTransformation\StepHandler\StepHandlerInterface;
use App\Service\AssetTransformation\TransformationPipelineException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;

final class HandlersHttpTest extends TestCase
{
    /**
     * Wraps a MockHttpClient in the same retry strategy used in services.yaml:
     * - 3 retries
     * - 200/400/800 ms backoff (delayMs=200, multiplier=2, max=800, jitter=0)
     * - Retry on 5xx + transport exceptions for POST
     * - NO retry on 4xx
     */
    private function makeRetryable(MockHttpClient $mock): RetryableHttpClient
    {
        $strategy = new GenericRetryStrategy(
            statusCodes: [
                0 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                423 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                425 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                429 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                500 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                502 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                503 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                504 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                507 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
                510 => ['GET', 'HEAD', 'OPTIONS', 'POST'],
            ],
            delayMs: 1, // shrink delay for tests
            multiplier: 2.0,
            maxDelayMs: 4,
            jitter: 0.0,
        );

        return new RetryableHttpClient($mock, $strategy, 3);
    }

    public function testResizeHandlerReturnsHandlerResultWithBytesAndContentType(): void
    {
        $captured = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['method'] = $method;
            $captured['url'] = $url;
            $captured['headers'] = $options['headers'] ?? [];
            $captured['body'] = is_callable($options['body']) ? $this->drainCallable($options['body']) : (string) ($options['body'] ?? '');
            return new MockResponse('XXX', [
                'http_code' => 200,
                'response_headers' => [
                    'content-type' => 'image/jpeg',
                    'x-render-duration-ms' => '42',
                ],
            ]);
        });

        $handler = new ResizeStepHandler($this->makeRetryable($mock), 2000);
        $result = $handler->run('rawbytes', ['width' => 800], 1500);

        self::assertInstanceOf(HandlerResult::class, $result);
        self::assertSame('XXX', $result->bytes);
        self::assertSame('image/jpeg', $result->contentType);
        self::assertSame(42, $result->renderMs);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/img/resize', $captured['url']);
        // Body is a multipart serialized stream; assert it carries our scalar params + raw bytes.
        self::assertStringContainsString('"width":800', $captured['body']);
        self::assertStringContainsString('rawbytes', $captured['body']);
        self::assertStringContainsString('name="image"', $captured['body']);
        self::assertStringContainsString('name="params"', $captured['body']);
    }

    public function testResizeHandlerRetriesOn503ThenSucceeds(): void
    {
        $callCount = 0;
        $mock = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;
            if ($callCount <= 3) {
                return new MockResponse('upstream busy', ['http_code' => 503]);
            }
            return new MockResponse('OK', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/png', 'x-render-duration-ms' => '10'],
            ]);
        });

        $handler = new ResizeStepHandler($this->makeRetryable($mock), 2000);
        $result = $handler->run('bytes', ['width' => 100], 5000);

        self::assertSame(4, $callCount, 'Expected 1 initial + 3 retries = 4 calls');
        self::assertSame('OK', $result->bytes);
    }

    public function testResizeHandlerDoesNotRetryOn422AndPreservesErrorBody(): void
    {
        $callCount = 0;
        $mock = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;
            return new MockResponse('{"detail":"width must be > 0"}', ['http_code' => 422]);
        });

        $handler = new ResizeStepHandler($this->makeRetryable($mock), 2000);

        try {
            $handler->run('bytes', ['width' => -1], 5000);
            self::fail('Expected TransformationPipelineException');
        } catch (TransformationPipelineException $e) {
            self::assertSame(1, $callCount, '4xx must not be retried');
            self::assertStringContainsString('422', $e->getMessage());
            self::assertStringContainsString('width must be > 0', $e->getMessage());
        }
    }

    public function testSupportedTypeReturnsCorrectEnumForEachHandler(): void
    {
        $mock = new MockHttpClient([]);
        $client = $this->makeRetryable($mock);

        self::assertSame(StepType::RESIZE, ResizeStepHandler::supportedType());
        self::assertSame(StepType::CROP, CropStepHandler::supportedType());
        self::assertSame(StepType::ROTATE, RotateStepHandler::supportedType());
        self::assertSame(StepType::FORMAT_CONVERT, FormatConvertStepHandler::supportedType());
        self::assertSame(StepType::ADD_BACKGROUND, AddBackgroundStepHandler::supportedType());

        // Each handler also exposes its default timeout.
        self::assertSame(2000, (new ResizeStepHandler($client, 2000))->defaultTimeoutMs());
        self::assertSame(2000, (new CropStepHandler($client, 2000))->defaultTimeoutMs());
        self::assertSame(2000, (new RotateStepHandler($client, 2000))->defaultTimeoutMs());
        self::assertSame(3000, (new FormatConvertStepHandler($client, 3000))->defaultTimeoutMs());
        self::assertSame(4000, (new AddBackgroundStepHandler($client, 4000))->defaultTimeoutMs());
    }

    public function testDefaultTimeoutMsReflectsConstructorInjection(): void
    {
        $mock = new MockHttpClient([]);
        $client = $this->makeRetryable($mock);

        // Different timeout values are passed straight from DI parameters.
        $h1 = new ResizeStepHandler($client, 1234);
        self::assertSame(1234, $h1->defaultTimeoutMs());

        $h2 = new FormatConvertStepHandler($client, 5678);
        self::assertSame(5678, $h2->defaultTimeoutMs());
    }

    public function testRetryOnTransportTimeoutThenSucceeds(): void
    {
        // GATING test for D-07: transport (timeout) exceptions must trigger retry.
        $callCount = 0;
        $mock = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;
            if ($callCount <= 2) {
                // MockHttpClient delivers the transport exception when stream is initiated.
                return new MockResponse('', ['error' => 'simulated timeout']);
            }
            return new MockResponse('OK', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'image/jpeg', 'x-render-duration-ms' => '5'],
            ]);
        });

        $handler = new ResizeStepHandler($this->makeRetryable($mock), 2000);
        $result = $handler->run('bytes', ['width' => 100], 5000);

        self::assertSame(3, $callCount, 'Expected 1 initial + 2 retries = 3 calls');
        self::assertSame('OK', $result->bytes);
    }

    /**
     * Helper: drain a callable body (used by Symfony multipart bodies in MockHttpClient).
     */
    private function drainCallable(callable $body): string
    {
        $out = '';
        while ('' !== ($chunk = $body(1024))) {
            $out .= $chunk;
        }
        return $out;
    }
}
