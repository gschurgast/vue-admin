<?php

namespace App\Validator;

use App\Entity\Product\ProductAttributeValue;
use App\Enum\AttributeType;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class AttributeValueRulesValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AttributeValueRules) {
            throw new UnexpectedTypeException($constraint, AttributeValueRules::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof ProductAttributeValue) {
            throw new UnexpectedValueException($value, ProductAttributeValue::class);
        }

        $definition = $value->getAttributeDefinition();
        if ($definition === null) {
            return;
        }

        $code = $definition->getCode();
        $type = $definition->getType();
        $rules = $definition->getValidationRules() ?? [];
        $required = $definition->getIsRequired();

        $raw = $value->getValue();
        $option = $value->getOption();
        $values = $value->getValues();

        $hasValue = ($raw !== null && $raw !== '')
            || $option !== null
            || (is_array($values) && count($values) > 0);

        if ($required && !$hasValue) {
            $this->context->buildViolation($constraint->missingMessage)
                ->setParameter('{{ code }}', $code)
                ->atPath('value')
                ->addViolation();
            return;
        }

        if (!$hasValue || $raw === null || $raw === '') {
            return;
        }

        match ($type) {
            AttributeType::TEXT, AttributeType::TEXTAREA, AttributeType::RICHTEXT
                => $this->validateText($raw, $rules, $code, $constraint),
            AttributeType::NUMBER, AttributeType::DECIMAL, AttributeType::INTEGER
                => $this->validateNumber($raw, $rules, $code, $type, $constraint),
            AttributeType::MEASURE
                => $this->validateMeasure($raw, $rules, $code, $constraint),
            AttributeType::JSON
                => $this->validateJson($raw, $code, $constraint),
            default => null,
        };
    }

    private function validateText(string $raw, array $rules, string $code, AttributeValueRules $c): void
    {
        $len = mb_strlen($raw);
        if (isset($rules['minLength']) && $len < (int) $rules['minLength']) {
            $this->addViolation($c->minLengthMessage, $code, ['{{ limit }}' => $rules['minLength']]);
        }
        if (isset($rules['maxLength']) && $len > (int) $rules['maxLength']) {
            $this->addViolation($c->maxLengthMessage, $code, ['{{ limit }}' => $rules['maxLength']]);
        }
        if (!empty($rules['pattern'])) {
            $delimited = '/' . str_replace('/', '\/', $rules['pattern']) . '/u';
            if (@preg_match($delimited, $raw) !== 1) {
                $this->addViolation($rules['patternMessage'] ?? $c->patternMessage, $code);
            }
        }
    }

    private function validateNumber(string $raw, array $rules, string $code, AttributeType $type, AttributeValueRules $c): void
    {
        if (!is_numeric($raw)) {
            $this->addViolation($c->invalidNumberMessage, $code);
            return;
        }
        $num = $type === AttributeType::INTEGER ? (int) $raw : (float) $raw;

        if (isset($rules['min']) && $num < $rules['min']) {
            $this->addViolation($c->minMessage, $code, ['{{ limit }}' => $rules['min']]);
        }
        if (isset($rules['max']) && $num > $rules['max']) {
            $this->addViolation($c->maxMessage, $code, ['{{ limit }}' => $rules['max']]);
        }
    }

    private function validateMeasure(string $raw, array $rules, string $code, AttributeValueRules $c): void
    {
        $parsed = json_decode($raw, true);
        if (!is_array($parsed) || !isset($parsed['value'])) {
            $this->addViolation($c->invalidJsonMessage, $code);
            return;
        }
        $num = $parsed['value'];
        if (!is_numeric($num)) {
            $this->addViolation($c->invalidNumberMessage, $code);
            return;
        }
        if (isset($rules['min']) && $num < $rules['min']) {
            $this->addViolation($c->minMessage, $code, ['{{ limit }}' => $rules['min']]);
        }
        if (isset($rules['max']) && $num > $rules['max']) {
            $this->addViolation($c->maxMessage, $code, ['{{ limit }}' => $rules['max']]);
        }
    }

    private function validateJson(string $raw, string $code, AttributeValueRules $c): void
    {
        json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addViolation($c->invalidJsonMessage, $code);
        }
    }

    private function addViolation(string $message, string $code, array $params = []): void
    {
        $builder = $this->context->buildViolation($message)
            ->setParameter('{{ code }}', $code)
            ->atPath('value');
        foreach ($params as $key => $val) {
            $builder->setParameter($key, (string) $val);
        }
        $builder->addViolation();
    }
}