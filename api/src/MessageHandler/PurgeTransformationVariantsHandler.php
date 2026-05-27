<?php

namespace App\MessageHandler;

use App\Message\PurgeTransformationVariantsMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Phase 1: no-op handler. Logs receipt and acknowledges.
 * Phase 3+: replace body with Flysystem deleteDirectory('transformations/{id}-v{hash8}/').
 */
#[AsMessageHandler]
final class PurgeTransformationVariantsHandler
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(PurgeTransformationVariantsMessage $message): void
    {
        $this->logger->info(
            'PurgeTransformationVariants received (no-op in Phase 1)',
            [
                'transformationId' => $message->transformationId,
                'versionHash'      => $message->versionHash,
                'hash8'            => substr($message->versionHash, 0, 8),
            ],
        );
    }
}
