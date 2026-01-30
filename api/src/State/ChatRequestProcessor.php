<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ChatRequest;
use App\Service\ChatService;
use App\Service\ConversationService;

class ChatRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private ChatService $chatService,
        private ConversationService $conversationService
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChatRequest
    {
        if (!$data instanceof ChatRequest) {
            throw new \InvalidArgumentException('Expected ChatRequest');
        }

        $conversationId = $data->conversationId ?? '';
        $message = $data->message ?? '';

        // Get history from Redis
        $history = $conversationId ? $this->conversationService->getHistory($conversationId) : [];

        // Send message with history
        $aiResponse = $this->chatService->sendMessage($message, $history);
        $data->response = $aiResponse;

        // Store in Redis
        if ($conversationId) {
            $this->conversationService->addMessage($conversationId, $message, $aiResponse);
        }

        return $data;
    }
}
