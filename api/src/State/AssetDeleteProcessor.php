<?php

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Asset\Asset;
use App\Service\Asset\AssetUploader;

/**
 * Deletes an asset row AND removes the underlying file from storage.
 */
class AssetDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AssetUploader $uploader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$operation instanceof DeleteOperationInterface) {
            return;
        }
        if (!$data instanceof Asset) {
            return;
        }
        $this->uploader->delete($data);
    }
}