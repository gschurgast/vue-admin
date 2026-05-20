<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class AttributeValueRules extends Constraint
{
    public string $missingMessage = 'A value is required for attribute "{{ code }}".';
    public string $minLengthMessage = 'Value for "{{ code }}" must be at least {{ limit }} characters long.';
    public string $maxLengthMessage = 'Value for "{{ code }}" cannot be longer than {{ limit }} characters.';
    public string $patternMessage = 'Value for "{{ code }}" does not match the required format.';
    public string $minMessage = 'Value for "{{ code }}" must be at least {{ limit }}.';
    public string $maxMessage = 'Value for "{{ code }}" must be at most {{ limit }}.';
    public string $invalidNumberMessage = 'Value for "{{ code }}" must be a valid number.';
    public string $invalidJsonMessage = 'Value for "{{ code }}" must be valid JSON.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
