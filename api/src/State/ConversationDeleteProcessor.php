<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\ConversationService;

class ConversationDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private ConversationService $conversationService
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $conversationId = $uriVariables['conversationId'] ?? null;

        if ($conversationId) {
            $this->conversationService->deleteConversation($conversationId);
        }

        return null;
    }
}
