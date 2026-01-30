<?php

namespace App\Service;

use Predis\Client;

class ConversationService
{
    private Client $redis;
    private const TTL = 86400; // 24 hours

    public function __construct(string $redisUrl)
    {
        $this->redis = new Client($redisUrl);
    }

    public function getHistory(string $conversationId): array
    {
        $data = $this->redis->get($this->getKey($conversationId));

        if (!$data) {
            return [];
        }

        return json_decode($data, true) ?: [];
    }

    public function addMessage(string $conversationId, string $message, string $response): void
    {
        $history = $this->getHistory($conversationId);

        $history[] = [
            'message' => $message,
            'response' => $response
        ];

        // Keep only last 50 messages to avoid memory issues
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        $this->redis->setex(
            $this->getKey($conversationId),
            self::TTL,
            json_encode($history)
        );
    }

    public function deleteConversation(string $conversationId): void
    {
        $this->redis->del($this->getKey($conversationId));
    }

    private function getKey(string $conversationId): string
    {
        return 'chat:' . $conversationId;
    }
}
