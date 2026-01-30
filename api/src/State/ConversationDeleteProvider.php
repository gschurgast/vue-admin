<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ConversationDelete;

class ConversationDeleteProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ConversationDelete
    {
        $conversation = new ConversationDelete();
        $conversation->conversationId = $uriVariables['conversationId'] ?? null;

        return $conversation;
    }
}
