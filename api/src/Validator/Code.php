<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Code extends Constraint
{
    public string $message = 'The code "{{ value }}" is invalid. It must contain only lowercase letters and underscores, start with a letter, not have consecutive underscores, and be at most 50 characters.';
    public int $maxLength = 50;
}
