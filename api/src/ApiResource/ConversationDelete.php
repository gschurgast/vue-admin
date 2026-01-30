<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
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
class ConversationDelete
{
    public ?string $conversationId = null;
}
