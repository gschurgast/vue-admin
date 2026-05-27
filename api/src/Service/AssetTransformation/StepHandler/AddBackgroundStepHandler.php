<?php

namespace App\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AddBackgroundStepHandler extends AbstractEmbedderStepHandler
{
    public function __construct(
        #[Autowire(service: 'embedder.client')] HttpClientInterface $embedderClient,
        #[Autowire(param: 'transformations.embedder_timeout_add_background_ms')] int $defaultTimeoutMs,
    ) {
        parent::__construct($embedderClient, $defaultTimeoutMs);
    }

    public static function supportedType(): StepType
    {
        return StepType::ADD_BACKGROUND;
    }

    protected function endpointPath(): string
    {
        return '/img/add-background';
    }
}
