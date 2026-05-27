<?php

namespace App\Service\AssetTransformation;

use App\Entity\AssetTransformation\AssetTransformation;
use App\Entity\AssetTransformation\TransformationStep;
use App\Enum\StepType;
use App\Service\AssetTransformation\StepHandler\StepHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thin sequential orchestrator for non-AI transformation steps (Phase 3 sync-only).
 *
 * Resolves the right {@see StepHandlerInterface} per {@see StepType}, pipes the
 * binary payload between handlers, enforces D-03 wall-clock cap (default 8s),
 * appends an implicit `format_convert` step when the URL extension differs from
 * the last produced format (D-09 specifics), and turns any embedder transport
 * error into a typed {@see TransformationPipelineException}.
 *
 * NOTE: The virtual implicit format_convert step is NOT persisted — the
 * persisted `versionHash` stays stable; cache divergence per output extension
 * is the caller's responsibility (storage key includes `.{ext}`).
 */
class PipelineRunner
{
    /** @var array<string, StepHandlerInterface> */
    private array $handlersByType = [];

    /**
     * @param iterable<StepHandlerInterface> $handlers Tagged services collected from `app.step_handler`.
     */
    public function __construct(
        #[AutowireIterator('app.step_handler')] iterable $handlers,
        #[Autowire(param: 'transformations.hard_cap_ms')] private readonly int $hardCapMs,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        foreach ($handlers as $h) {
            // Resolve type via the static contract; test stubs may also expose
            // a dynamic resolver to allow multiple concrete handler instances.
            $type = method_exists($h, 'supportedTypeDynamic')
                ? $h->supportedTypeDynamic()
                : $h::supportedType();
            $this->handlersByType[$type->value] = $h;
        }
    }

    /**
     * Test-only convenience: build a runner from a pre-resolved type → handler map.
     * Avoids relying on the static `supportedType()` when stubs share the class.
     *
     * @param array<string, StepHandlerInterface> $handlersByType
     */
    public static function fromMap(array $handlersByType, int $hardCapMs, LoggerInterface $logger): self
    {
        $self = new self([], $hardCapMs, $logger);
        $self->handlersByType = $handlersByType;
        return $self;
    }

    /**
     * Run the pipeline.
     *
     * @param AssetTransformation $tx        Source transformation (persisted, with steps)
     * @param string              $bytes     Original asset bytes (already loaded from Flysystem)
     * @param string              $outputExt Requested output extension (from `/t/{code}/{id}.{ext}`)
     *
     * @throws TransformationPipelineException
     */
    public function run(AssetTransformation $tx, string $bytes, string $outputExt): PipelineResult
    {
        $outputExt = strtolower(ltrim($outputExt, '.'));
        $start = (int) (microtime(true) * 1000);
        $applied = [];
        $contentType = 'application/octet-stream';

        // Snapshot + deterministic order (position ASC, defensive — collection is
        // already #[OrderBy] but the entity might be filled by hand in tests).
        $steps = $tx->getSteps()->toArray();
        usort($steps, fn(TransformationStep $a, TransformationStep $b) => $a->getPosition() <=> $b->getPosition());

        // Decide whether to append an implicit format_convert based on the last
        // format produced by the persisted chain (D-09 specifics).
        $lastFormat = null;
        foreach ($steps as $s) {
            if ($s->getType() === StepType::FORMAT_CONVERT) {
                $lastFormat = strtolower((string) ($s->getParams()['format'] ?? ''));
            }
        }
        $normalizedRequested = $this->normalizeExt($outputExt);
        $normalizedLast = $lastFormat === null ? null : $this->normalizeExt($lastFormat);
        $needsImplicitConvert = ($normalizedLast !== $normalizedRequested);

        // Build the effective (possibly virtual-extended) step list WITHOUT
        // mutating the persisted entity.
        $effectiveSteps = $steps;
        if ($needsImplicitConvert) {
            $effectiveSteps[] = $this->makeVirtualFormatConvertStep($outputExt);
        }

        foreach ($effectiveSteps as $step) {
            $type = $step->getType();
            if (!$type instanceof StepType || !isset($this->handlersByType[$type->value])) {
                throw new TransformationPipelineException(
                    sprintf('Unsupported step type: %s', $type?->value ?? 'null'),
                    TransformationPipelineException::CODE_UNSUPPORTED_STEP,
                );
            }
            $handler = $this->handlersByType[$type->value];

            $elapsed = (int) (microtime(true) * 1000) - $start;
            $remaining = $this->hardCapMs - $elapsed;
            if ($remaining <= 0) {
                throw new TransformationPipelineException(
                    sprintf('Hard cap %dms exceeded before step %s', $this->hardCapMs, $type->value),
                    TransformationPipelineException::CODE_CAP_EXCEEDED,
                );
            }
            $timeoutMs = min($handler->defaultTimeoutMs(), $remaining);

            try {
                $res = $handler->run($bytes, $step->getParams() ?? [], $timeoutMs);
            } catch (TransportExceptionInterface $e) {
                throw new TransformationPipelineException(
                    sprintf('Embedder transport error on %s: %s', $type->value, $e->getMessage()),
                    TransformationPipelineException::CODE_EMBEDDER_ERROR,
                    $e,
                );
            }
            $bytes = $res->bytes;
            $contentType = $res->contentType;
            $applied[] = $type->value;
        }

        $totalMs = (int) (microtime(true) * 1000) - $start;
        if ($totalMs > $this->hardCapMs) {
            $this->logger->warning('Pipeline exceeded hard cap', [
                'transformation_code' => $tx->getCode(),
                'total_ms' => $totalMs,
                'cap_ms' => $this->hardCapMs,
                'applied' => $applied,
            ]);
            throw new TransformationPipelineException(
                sprintf('Hard cap exceeded (post): %dms', $totalMs),
                TransformationPipelineException::CODE_CAP_EXCEEDED,
            );
        }

        return new PipelineResult($bytes, $contentType, $totalMs, $tx->getWarnings(), $applied);
    }

    private function normalizeExt(string $ext): string
    {
        return $ext === 'jpg' ? 'jpeg' : $ext;
    }

    /**
     * Virtual step — NOT persisted (does not contribute to versionHash). It only
     * exists for the duration of one `run()` call to guarantee the wire-format
     * matches the URL extension.
     */
    private function makeVirtualFormatConvertStep(string $ext): TransformationStep
    {
        $s = new TransformationStep();
        $s->setType(StepType::FORMAT_CONVERT);
        $s->setPosition(PHP_INT_MAX);
        $s->setParams(['format' => $ext]);
        return $s;
    }
}
