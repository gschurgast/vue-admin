<?php

declare(strict_types=1);

namespace App\Service\AssetTransformation\StepHandler;

use App\Enum\StepType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SymmetryStepHandler extends AbstractEmbedderStepHandler
{
    public function __construct(
        #[Autowire(service: 'embedder.client')] HttpClientInterface $embedderClient,
        #[Autowire(param: 'transformations.embedder_timeout_symmetry_ms')] int $defaultTimeoutMs,
    ) {
        parent::__construct($embedderClient, $defaultTimeoutMs);
    }

    public static function supportedType(): StepType
    {
        return StepType::SYMMETRY;
    }

    protected function endpointPath(): string
    {
        return '/img/symmetry';
    }
}