<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatService
{
    private string $apiKey;
    private string $model = 'gpt-3.5-turbo';

    public function __construct(
        private HttpClientInterface $httpClient,
        string $openaiApiKey
    ) {
        $this->apiKey = $openaiApiKey;
    }

    public function sendMessage(string $message, array $conversationHistory = []): string
    {
        try {
            // Build messages array starting with system message
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful assistant. Help users with their questions'
                ]
            ];

            // Add conversation history
            foreach ($conversationHistory as $historyItem) {
                $messages[] = [
                    'role' => 'user',
                    'content' => $historyItem['message']
                ];
                if (isset($historyItem['response'])) {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => $historyItem['response']
                    ];
                }
            }

            // Add current message
            $messages[] = [
                'role' => 'user',
                'content' => $message
            ];

            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => $messages,
                    'max_tokens' => 500,
                    'temperature' => 0.7,
                ],
            ]);

            $data = $response->toArray();

            return $data['choices'][0]['message']['content'] ?? 'Sorry, I could not generate a response.';
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to communicate with OpenAI: ' . $e->getMessage());
        }
    }
}
