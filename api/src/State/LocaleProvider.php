<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\LocaleRequest;
use App\Enum\Locale;

class LocaleProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            fn(Locale $locale) => new LocaleRequest(
                $locale->value,
                $locale->label(),
                $locale->flag()
            ),
            Locale::cases()
        );
    }
}
