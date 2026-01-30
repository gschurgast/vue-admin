<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Attribute\MenuGroup;
use App\State\LocaleProvider;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/locales',
            provider: LocaleProvider::class
        )
    ]
)]
#[MenuGroup('hidden')]
class LocaleRequest
{
    #[ApiProperty(identifier: true)]
    public string $code;
    public string $label;
    public string $flag;

    public function __construct(string $code = '', string $label = '', string $flag = '')
    {
        $this->code = $code;
        $this->label = $label;
        $this->flag = $flag;
    }
}
