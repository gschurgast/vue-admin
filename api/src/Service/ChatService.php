<?php

namespace App\Service;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

class ChatService
{
    private const SYSTEM_PROMPT = 'You are a helpful assistant. Help users with their questions.';
    private const MODEL = 'gpt-4o-mini';

    public function __construct(
        private PlatformInterface $platform
    ) {}

    public function sendMessage(string $message, array $conversationHistory = []): string
    {
        $messages = new MessageBag();
        $messages->add(Message::forSystem(self::SYSTEM_PROMPT));

        // Add conversation history
        foreach ($conversationHistory as $historyItem) {
            $messages->add(Message::ofUser($historyItem['message']));
            if (isset($historyItem['response'])) {
                $messages->add(Message::ofAssistant($historyItem['response']));
            }
        }

        // Add current message
        $messages->add(Message::ofUser($message));

        $response = $this->platform->invoke(
            model: self::MODEL,
            input: $messages
        );

        $content = $response->asText();

        return $content ?: 'Sorry, I could not generate a response.';
    }
}
