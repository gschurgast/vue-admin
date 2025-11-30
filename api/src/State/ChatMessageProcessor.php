<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ChatMessage;
use App\Service\ChatService;
use Doctrine\ORM\EntityManagerInterface;

class ChatMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private ChatService $chatService,
        private EntityManagerInterface $entityManager
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChatMessage
    {
        if (!$data instanceof ChatMessage) {
            throw new \InvalidArgumentException('Expected ChatMessage entity');
        }

        // Get conversation history (last 10 messages for context)
        $repository = $this->entityManager->getRepository(ChatMessage::class);
        $previousMessages = $repository->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        // Reverse to get chronological order
        $conversationHistory = array_reverse(array_map(function ($msg) {
            return [
                'message' => $msg->getMessage(),
                'response' => $msg->getResponse()
            ];
        }, $previousMessages));

        // Get AI response with conversation context
        $aiResponse = $this->chatService->sendMessage($data->getMessage(), $conversationHistory);
        $data->setResponse($aiResponse);

        // Persist to database
        $this->entityManager->persist($data);
        $this->entityManager->flush();

        return $data;
    }
}
