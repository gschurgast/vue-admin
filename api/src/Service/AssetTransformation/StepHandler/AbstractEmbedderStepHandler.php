<?php

namespace App\Service\AssetTransformation\StepHandler;

use App\Service\AssetTransformation\TransformationPipelineException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared HTTP-to-embedder behavior for every step handler.
 *
 * Concrete subclasses only declare their endpoint path + StepType. The HTTP
 * dance (multipart encoding, status check, retry semantics via the injected
 * `embedder.client`, error wrapping) lives here.
 */
abstract class AbstractEmbedderStepHandler implements StepHandlerInterface
{
    public function __construct(
        protected readonly HttpClientInterface $embedderClient,
        protected readonly int $defaultTimeoutMsValue,
    ) {
    }

    public function defaultTimeoutMs(): int
    {
        return $this->defaultTimeoutMsValue;
    }

    public function run(string $bytes, array $params, int $timeoutMs): HandlerResult
    {
        $start = (int) (microtime(true) * 1000);

        $form = new FormDataPart([
            'image' => new DataPart($bytes, 'input', 'application/octet-stream'),
            'params' => json_encode($params, JSON_THROW_ON_ERROR),
        ]);

        $headers = $form->getPreparedHeaders()->toArray();
        $headers[] = 'Accept: image/*';

        $path = $this->endpointPath();

        try {
            $response = $this->embedderClient->request('POST', $path, [
                'timeout' => max(0.1, $timeoutMs / 1000.0),
                'headers' => $headers,
                'body' => $form->bodyToIterable(),
            ]);
            $status = $response->getStatusCode(); // triggers I/O / retry strategy
        } catch (TransportExceptionInterface $e) {
            throw new TransformationPipelineException(
                sprintf('embedder %s transport error after retries: %s', $path, $e->getMessage()),
                TransformationPipelineException::CODE_EMBEDDER_ERROR,
                $e,
            );
        }

        if ($status >= 400) {
            throw new TransformationPipelineException(
                sprintf('embedder %s → %d: %s', $path, $status, $response->getContent(false)),
                TransformationPipelineException::CODE_EMBEDDER_ERROR,
            );
        }

        $rh = $response->getHeaders(false);
        $contentType = $rh['content-type'][0] ?? 'application/octet-stream';
        $renderMs = isset($rh['x-render-duration-ms'][0])
            ? (int) $rh['x-render-duration-ms'][0]
            : ((int) (microtime(true) * 1000) - $start);

        return new HandlerResult($response->getContent(), $contentType, $renderMs);
    }

    /**
     * Path on the embedder service (relative to its base URI).
     * e.g. `/img/resize`, `/img/format-convert`, …
     */
    abstract protected function endpointPath(): string;
}
