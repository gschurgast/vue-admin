<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class TransformationCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TransformationCode) {
            throw new UnexpectedTypeException($constraint, TransformationCode::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // Blocklist first ('t' is both reserved and mono-char → "reserved" message wins).
        if (in_array($value, $constraint->reservedCodes, strict: true)) {
            $this->context->buildViolation($constraint->reservedMessage)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->addViolation();
            return;
        }

        if (strlen($value) > $constraint->maxLength) {
            $this->addStandard($constraint, $value);
            return;
        }

        if (strlen($value) === 1) {
            $this->addStandard($constraint, $value);
            return;
        }

        if (!preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $value)) {
            $this->addStandard($constraint, $value);
        }
    }

    private function addStandard(TransformationCode $c, string $value): void
    {
        $this->context->buildViolation($c->message)
            ->setParameter('{{ value }}', $this->formatValue($value))
            ->addViolation();
    }
}
