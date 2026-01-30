<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\ChatRequestProcessor;
use Symfony\Component\Serializer\Annotation\Groups;

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
class ChatRequest
{
    #[Groups(['chat:read', 'chat:write'])]
    public ?string $message = null;

    #[Groups(['chat:read'])]
    public ?string $response = null;
}
