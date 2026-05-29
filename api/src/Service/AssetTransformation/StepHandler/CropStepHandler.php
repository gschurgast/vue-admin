<?php

namespace App\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CropStepHandler extends AbstractEmbedderStepHandler
{
    public function __construct(
        #[Autowire(service: 'embedder.client')] HttpClientInterface $embedderClient,
        #[Autowire(param: 'transformations.embedder_timeout_crop_ms')] int $defaultTimeoutMs,
    ) {
        parent::__construct($embedderClient, $defaultTimeoutMs);
    }

    public static function supportedType(): StepType
    {
        return StepType::CROP;
    }

    protected function endpointPath(): string
    {
        return '/img/crop';
    }

    /**
     * Override pour convertir `aspectRatio` "W:H" (string, contrat PHP/PWA)
     * → float (contrat Python `img_crop.py`). Le PHP/UX expose "16:9" qui est
     * ergonomique ; le Python attend `1.7778`. La conversion vit ici, à la
     * frontière HTTP, pour ne pas polluer le DTO ni le runner.
     */
    public function run(string $bytes, array $params, int $timeoutMs): HandlerResult
    {
        if (isset($params['aspectRatio']) && is_string($params['aspectRatio'])) {
            if (preg_match('/^(\d+):(\d+)$/', $params['aspectRatio'], $m)) {
                $w = (int) $m[1];
                $h = (int) $m[2];
                if ($w > 0 && $h > 0) {
                    $params['aspectRatio'] = $w / $h;
                }
            }
        }
        return parent::run($bytes, $params, $timeoutMs);
    }
}
