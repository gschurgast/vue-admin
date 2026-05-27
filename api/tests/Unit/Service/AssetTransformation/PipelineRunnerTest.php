<?php

namespace App\Tests\Unit\Service\AssetTransformation;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\PipelineResult;
use App\Service\AssetTransformation\PipelineRunner;
use App\Service\AssetTransformation\StepHandler\HandlerResult;
use App\Service\AssetTransformation\StepHandler\StepHandlerInterface;
use App\Service\AssetTransformation\TransformationPipelineException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PipelineRunnerTest extends TestCase
{
    /**
     * Build a stub handler for a given StepType. Each invocation records its call
     * and optionally sleeps to simulate latency (for the wall-clock cap test).
     */
    private function makeHandler(
        StepType $type,
        string $outputBytes,
        string $contentType = 'image/jpeg',
        int $defaultTimeoutMs = 2000,
        int $sleepMs = 0,
        array &$calls = [],
    ): StepHandlerInterface {
        return new class($type, $outputBytes, $contentType, $defaultTimeoutMs, $sleepMs, $calls) implements StepHandlerInterface {
            /** @param array<int,array{type:string,params:array<string,mixed>,timeoutMs:int,inputLen:int}> $calls */
            public function __construct(
                private readonly StepType $stepType,
                private readonly string $outputBytes,
                private readonly string $contentType,
                private readonly int $defaultTimeoutMsValue,
                private readonly int $sleepMs,
                private array &$calls,
            ) {}

            public static function supportedType(): StepType
            {
                // Cannot read instance state from static; tests use supportedTypeDynamic() below.
                return StepType::RESIZE;
            }

            public function supportedTypeDynamic(): StepType
            {
                return $this->stepType;
            }

            public function defaultTimeoutMs(): int
            {
                return $this->defaultTimeoutMsValue;
            }

            public function run(string $bytes, array $params, int $timeoutMs): HandlerResult
            {
                $this->calls[] = [
                    'type' => $this->stepType->value,
                    'params' => $params,
                    'timeoutMs' => $timeoutMs,
                    'inputLen' => strlen($bytes),
                ];
                if ($this->sleepMs > 0) {
                    usleep($this->sleepMs * 1000);
                }
                return new HandlerResult($this->outputBytes, $this->contentType, 1);
            }
        };
    }

    /**
     * Build a PipelineRunner from an array of [StepType => handler] stubs.
     * @param array<string, StepHandlerInterface> $handlersByType
     */
    private function makeRunner(array $handlersByType, int $hardCapMs = 8000): PipelineRunner
    {
        return PipelineRunner::fromMap($handlersByType, $hardCapMs, new NullLogger());
    }

    private function makeStep(StepType $type, array $params, int $position): TransformationStep
    {
        $s = new TransformationStep();
        $s->setType($type);
        $s->setParams($params);
        $s->setPosition($position);
        return $s;
    }

    private function makeTx(array $steps): AssetTransformation
    {
        $tx = new AssetTransformation();
        foreach ($steps as $s) {
            $tx->addStep($s);
        }
        return $tx;
    }

    public function testRunsStepsInOrderAndReturnsFinalBytes(): void
    {
        $calls = [];
        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'resized', 'image/png', 2000, 0, $calls),
            StepType::FORMAT_CONVERT->value => $this->makeHandler(StepType::FORMAT_CONVERT, 'webp-bytes', 'image/webp', 3000, 0, $calls),
        ];
        $tx = $this->makeTx([
            $this->makeStep(StepType::RESIZE, ['width' => 800], 0),
            $this->makeStep(StepType::FORMAT_CONVERT, ['format' => 'webp'], 1),
        ]);

        $runner = $this->makeRunner($handlers);
        $result = $runner->run($tx, 'original', 'webp');

        self::assertInstanceOf(PipelineResult::class, $result);
        self::assertSame('webp-bytes', $result->bytes);
        self::assertSame('image/webp', $result->contentType);
        self::assertSame(['resize', 'format_convert'], $result->appliedSteps);
        self::assertCount(2, $calls);
        self::assertSame('resize', $calls[0]['type']);
        self::assertSame('format_convert', $calls[1]['type']);
    }

    public function testAppendsImplicitFormatConvertWhenOutputExtDiffers(): void
    {
        $calls = [];
        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'resized', 'image/png', 2000, 0, $calls),
            StepType::FORMAT_CONVERT->value => $this->makeHandler(StepType::FORMAT_CONVERT, 'jpg-bytes', 'image/jpeg', 3000, 0, $calls),
        ];
        $tx = $this->makeTx([
            $this->makeStep(StepType::RESIZE, ['width' => 800], 0),
        ]);

        $runner = $this->makeRunner($handlers);
        $result = $runner->run($tx, 'original', 'jpg');

        self::assertSame('jpg-bytes', $result->bytes);
        self::assertSame('image/jpeg', $result->contentType);
        self::assertCount(2, $calls);
        self::assertSame('resize', $calls[0]['type']);
        self::assertSame('format_convert', $calls[1]['type']);
        // Implicit format_convert must carry the URL-requested extension.
        self::assertSame('jpg', $calls[1]['params']['format']);
        // The persisted transformation MUST NOT have been mutated.
        self::assertCount(1, $tx->getSteps(), 'Virtual step must NOT be persisted');
    }

    public function testAppendsImplicitFormatConvertEvenAfterExplicitFormatConvertWithDifferentExt(): void
    {
        $calls = [];
        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'r', 'image/png', 2000, 0, $calls),
            StepType::FORMAT_CONVERT->value => $this->makeHandler(StepType::FORMAT_CONVERT, 'fc', 'image/jpeg', 3000, 0, $calls),
        ];
        $tx = $this->makeTx([
            $this->makeStep(StepType::RESIZE, ['width' => 800], 0),
            $this->makeStep(StepType::FORMAT_CONVERT, ['format' => 'webp'], 1),
        ]);

        $runner = $this->makeRunner($handlers);
        $result = $runner->run($tx, 'original', 'jpg');

        self::assertSame(3, count($calls), 'resize + explicit webp + implicit jpg = 3 calls');
        self::assertSame('resize', $calls[0]['type']);
        self::assertSame('format_convert', $calls[1]['type']);
        self::assertSame('webp', $calls[1]['params']['format']);
        self::assertSame('format_convert', $calls[2]['type']);
        self::assertSame('jpg', $calls[2]['params']['format']);
        self::assertSame(['resize', 'format_convert', 'format_convert'], $result->appliedSteps);
    }

    public function testHardCapExceededRaisesCapException(): void
    {
        $calls = [];
        // Each handler sleeps 60ms; cap is 50ms → must throw before the 2nd step.
        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'r', 'image/png', 2000, 60, $calls),
            StepType::FORMAT_CONVERT->value => $this->makeHandler(StepType::FORMAT_CONVERT, 'fc', 'image/jpeg', 3000, 60, $calls),
        ];
        $tx = $this->makeTx([
            $this->makeStep(StepType::RESIZE, ['width' => 800], 0),
            $this->makeStep(StepType::FORMAT_CONVERT, ['format' => 'jpg'], 1),
        ]);

        $runner = $this->makeRunner($handlers, 50);

        try {
            $runner->run($tx, 'original', 'jpg');
            self::fail('Expected TransformationPipelineException CAP_EXCEEDED');
        } catch (TransformationPipelineException $e) {
            self::assertSame(TransformationPipelineException::CODE_CAP_EXCEEDED, $e->getCode());
        }
    }

    public function testRemainingMsDecreasesAcrossSteps(): void
    {
        $calls = [];
        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'r', 'image/png', 5000, 30, $calls),
            StepType::FORMAT_CONVERT->value => $this->makeHandler(StepType::FORMAT_CONVERT, 'fc', 'image/jpeg', 5000, 0, $calls),
        ];
        $tx = $this->makeTx([
            $this->makeStep(StepType::RESIZE, ['width' => 800], 0),
            $this->makeStep(StepType::FORMAT_CONVERT, ['format' => 'jpg'], 1),
        ]);

        // Cap 500ms — both handlers advertise 5000ms default but the runner must
        // clamp by remainingMs so the second step receives strictly less than the first.
        $runner = $this->makeRunner($handlers, 500);
        $runner->run($tx, 'original', 'jpg');

        self::assertGreaterThanOrEqual(2, count($calls));
        $firstTimeout = $calls[0]['timeoutMs'];
        $secondTimeout = $calls[1]['timeoutMs'];
        self::assertLessThan($firstTimeout, $secondTimeout, 'remainingMs must decrease across steps');
        self::assertLessThanOrEqual(500, $firstTimeout);
    }

    public function testUnsupportedStepTypeRaises(): void
    {
        // remove_background is intentionally not registered in Phase 3 (sync-only).
        $calls = [];
        $handlers = [
            StepType::RESIZE->value => $this->makeHandler(StepType::RESIZE, 'r', 'image/png', 2000, 0, $calls),
        ];
        $tx = $this->makeTx([
            $this->makeStep(StepType::REMOVE_BACKGROUND, [], 0),
        ]);

        $runner = $this->makeRunner($handlers);

        try {
            $runner->run($tx, 'original', 'png');
            self::fail('Expected TransformationPipelineException CODE_UNSUPPORTED_STEP');
        } catch (TransformationPipelineException $e) {
            self::assertSame(TransformationPipelineException::CODE_UNSUPPORTED_STEP, $e->getCode());
            self::assertStringContainsString('remove_background', $e->getMessage());
        }
    }
}
