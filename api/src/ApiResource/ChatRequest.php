<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Attribute\MenuGroup;
use App\State\ChatRequestProcessor;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/chat',
            processor: ChatRequestProcessor::class,
            normalizationContext: ['groups' => ['chat:read']],
            denormalizationContext: ['groups' => ['chat:write']]
        )
    ]
)]
#[MenuGroup('hidden')]
class ChatRequest
{
    #[Groups(['chat:read', 'chat:write'])]
    public ?string $message = null;

    #[Groups(['chat:write'])]
    public ?string $conversationId = null;

    #[Groups(['chat:write'])]
    public ?array $pageContext = null;

    #[Groups(['chat:read'])]
    public ?string $response = null;
}
