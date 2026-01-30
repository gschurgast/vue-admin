<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class RelationEndpointImmutableIfUsed extends Constraint
{
    public string $message = 'Cannot change the relation endpoint because attribute values already exist for this definition. Delete the existing values first.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}