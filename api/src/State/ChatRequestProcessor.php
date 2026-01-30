<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ChatRequest;
use App\Service\ChatService;

class ChatRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private ChatService $chatService
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChatRequest
    {
        if (!$data instanceof ChatRequest) {
            throw new \InvalidArgumentException('Expected ChatRequest');
        }

        $aiResponse = $this->chatService->sendMessage($data->message ?? '');
        $data->response = $aiResponse;

        return $data;
    }
}
