<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class TransformationCode extends Constraint
{
    public string $message = 'The code {{ value }} is invalid. It must be kebab-case (lowercase letters, digits, hyphens), start with a letter, no consecutive hyphens, between 2 and 50 chars.';
    public string $reservedMessage = 'The code {{ value }} is reserved and cannot be used.';

    /** @var list<string> */
    public array $reservedCodes = ['api', 'admin', 't', '_', 'assets'];

    public int $maxLength = 50;
}
