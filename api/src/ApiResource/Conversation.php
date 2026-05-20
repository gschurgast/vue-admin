<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Attribute\MenuGroup;
use App\State\ConversationProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/conversations/{conversationId}',
            provider: ConversationProvider::class,
            normalizationContext: ['groups' => ['conversation:read']]
        )
    ]
)]
#[MenuGroup('hidden')]
class Conversation
{
    #[Groups(['conversation:read'])]
    public ?string $conversationId = null;

    #[Groups(['conversation:read'])]
    public array $messages = [];
}
