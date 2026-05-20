<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Conversation;
use App\Service\ConversationService;

class ConversationProvider implements ProviderInterface
{
    public function __construct(
        private ConversationService $conversationService
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Conversation
    {
        $conversationId = $uriVariables['conversationId'] ?? null;

        if (!$conversationId) {
            return null;
        }

        $history = $this->conversationService->getHistory($conversationId);

        $conversation = new Conversation();
        $conversation->conversationId = $conversationId;
        $conversation->messages = $history;

        return $conversation;
    }
}
