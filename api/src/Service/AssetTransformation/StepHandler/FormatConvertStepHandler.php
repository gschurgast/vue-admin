<?php

namespace App\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FormatConvertStepHandler extends AbstractEmbedderStepHandler
{
    public function __construct(
        #[Autowire(service: 'embedder.client')] HttpClientInterface $embedderClient,
        #[Autowire(param: 'transformations.embedder_timeout_format_convert_ms')] int $defaultTimeoutMs,
    ) {
        parent::__construct($embedderClient, $defaultTimeoutMs);
    }

    public static function supportedType(): StepType
    {
        return StepType::FORMAT_CONVERT;
    }

    protected function endpointPath(): string
    {
        return '/img/format-convert';
    }
}
