<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use App\Attribute\MenuGroup;
use App\State\ConversationDeleteProcessor;
use App\State\ConversationDeleteProvider;

#[ApiResource(
    operations: [
        new Delete(
            uriTemplate: '/conversations/{conversationId}',
            provider: ConversationDeleteProvider::class,
            processor: ConversationDeleteProcessor::class
        )
    ]
)]
#[MenuGroup('hidden')]
class ConversationDelete
{
    public ?string $conversationId = null;
}
