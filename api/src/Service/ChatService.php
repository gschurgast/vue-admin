<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Target;

class ChatService
{
    public function __construct(
        #[Target('assistant')]
        private AgentInterface $agent,
        private LoggerInterface $logger,
    ) {}

    public function sendMessage(string $message, array $conversationHistory = [], ?array $pageContext = null): string
    {
        $messages = new MessageBag();

        // Add page context as a system message if available
        if ($pageContext && !empty($pageContext['resourceIri'])) {
            $contextMessage = $this->buildContextMessage($pageContext);
            $messages->add(Message::forSystem($contextMessage));
        }

        // Add conversation history
        foreach ($conversationHistory as $historyItem) {
            $messages->add(Message::ofUser($historyItem['message']));
            if (isset($historyItem['response'])) {
                $messages->add(Message::ofAssistant($historyItem['response']));
            }
        }

        // Add current message
        $messages->add(Message::ofUser($message));

        try {
            $this->logger->info('Calling agent with messages: ' . count($messages));
            $response = $this->agent->call($messages);
            $content = $response->getContent();

            // Handle different response types
            if ($content instanceof \Symfony\AI\Platform\Result\TextResult) {
                $text = $content->getContent();
            } elseif (is_array($content)) {
                $firstItem = $content[0] ?? '';
                $text = $firstItem instanceof \Symfony\AI\Platform\Result\TextResult
                    ? $firstItem->getContent()
                    : (string) $firstItem;
            } else {
                $text = (string) $content;
            }

            $this->logger->info('Agent response: ' . substr($text, 0, 500));
            return $text ?: 'Sorry, I could not generate a response.';
        } catch (\Throwable $e) {
            $this->logger->error('Agent error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 'Error: ' . $e->getMessage();
        }
    }

    private function buildContextMessage(array $pageContext): string
    {
        $parts = ['CURRENT PAGE CONTEXT - The user is viewing this resource:'];

        if (!empty($pageContext['resourceType'])) {
            $parts[] = sprintf('- Resource type: %s', $pageContext['resourceType']);
        }

        if (!empty($pageContext['resourceIri'])) {
            $parts[] = sprintf('- Resource IRI: %s', $pageContext['resourceIri']);
        }

        if (!empty($pageContext['pageType'])) {
            $parts[] = sprintf('- Page type: %s', $pageContext['pageType']);
        }

        $parts[] = '';
        $parts[] = 'INSTRUCTION: When the user asks ANY question about the current resource (dates, data, fields, etc.), ';
        $parts[] = 'you MUST call get_current_resource with resourceIri="' . ($pageContext['resourceIri'] ?? '') . '" to fetch the data BEFORE answering.';
        $parts[] = 'Do NOT ask clarifying questions if you can get the answer by fetching the resource.';

        return implode("\n", $parts);
    }
}
