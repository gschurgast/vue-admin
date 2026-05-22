<?php

namespace App\Service\Asset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client to the embedder microservice (Python/FastAPI/CLIP).
 *
 * The embedder is reachable in-cluster at http://embedder:8000. We don't
 * expose it publicly — it must never receive untrusted traffic.
 */
class AssetEmbedder
{
    private const ENDPOINT_PATH = '/embed';
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(param: 'app.embedder_url')]
        private readonly string $baseUrl = 'http://embedder:8000',
    ) {
    }

    /**
     * Send the binary content of an image and receive a 512-d L2-normalised vector.
     *
     * @param resource|string $contents stream or raw bytes
     * @return array{embedding: float[], model: string, dim: int}
     */
    public function embed($contents, string $filename = 'image', string $mimeType = 'application/octet-stream'): array
    {
        $url = rtrim($this->baseUrl, '/') . self::ENDPOINT_PATH;

        if (is_resource($contents)) {
            $raw = stream_get_contents($contents);
            if ($raw === false) {
                throw new AssetUploadException('Unable to read asset stream for embedding.');
            }
        } else {
            $raw = $contents;
        }

        $boundary = '----embedder' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            . sprintf("Content-Disposition: form-data; name=\"file\"; filename=\"%s\"\r\n", addslashes($filename))
            . sprintf("Content-Type: %s\r\n\r\n", $mimeType)
            . $raw . "\r\n"
            . "--{$boundary}--\r\n";

        $response = $this->httpClient->request('POST', $url, (new HttpOptions())
            ->setBody($body)
            ->setHeaders([
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ])
            ->setTimeout(self::TIMEOUT_SECONDS)
            ->toArray()
        );

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new AssetUploadException(sprintf(
                'Embedder returned HTTP %d: %s',
                $status,
                substr($response->getContent(false), 0, 200),
            ));
        }

        $data = $response->toArray();
        if (!isset($data['embedding'], $data['model'], $data['dim']) || !is_array($data['embedding'])) {
            throw new AssetUploadException('Embedder returned malformed payload.');
        }

        return [
            'embedding' => array_map(static fn ($v) => (float) $v, $data['embedding']),
            'model' => (string) $data['model'],
            'dim' => (int) $data['dim'],
        ];
    }
}
