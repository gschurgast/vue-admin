<?php

namespace App\Enum;

enum ParameterType: string
{
    case STRING = 'string';
    case INTEGER = 'integer';
    case BOOLEAN = 'boolean';
    case JSON = 'json';

    public function label(): string
    {
        return match ($this) {
            self::STRING => 'String',
            self::INTEGER => 'Integer',
            self::BOOLEAN => 'Boolean',
            self::JSON => 'JSON',
        };
    }

    /** @return string[] */
    public static function allCodes(): array
    {
        return array_map(fn(self $c) => $c->value, self::cases());
    }

    /** @return array<string, string> */
    public static function toArray(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }
        return $out;
    }
}
