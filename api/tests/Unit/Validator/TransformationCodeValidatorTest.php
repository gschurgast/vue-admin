<?php

namespace App\Tests\Unit\Validator;

use App\Validator\TransformationCode;
use App\Validator\TransformationCodeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

final class TransformationCodeValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): TransformationCodeValidator
    {
        return new TransformationCodeValidator();
    }

    #[DataProvider('validCodes')]
    public function testValidCodesProduceNoViolation(string $code): void
    {
        $this->validator->validate($code, new TransformationCode());
        $this->assertNoViolation();
    }

    public static function validCodes(): array
    {
        return [['hero-webp'], ['thumb-product'], ['p-1'], ['x-1-2-3'], ['abc']];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodesProduceStandardViolation(string $code): void
    {
        $constraint = new TransformationCode();
        $this->validator->validate($code, $constraint);
        $this->buildViolation($constraint->message)
            ->setParameter('{{ value }}', '"'.$code.'"')
            ->assertRaised();
    }

    public static function invalidCodes(): array
    {
        return [
            ['UPPER'],
            ['hero_webp'],
            ['-leading'],
            ['trailing-'],
            ['double--hyphen'],
            ['with space'],
            [str_repeat('a-', 30)],
            ['a'],
        ];
    }

    #[DataProvider('reservedCodes')]
    public function testReservedCodesProduceReservedViolation(string $code): void
    {
        $constraint = new TransformationCode();
        $this->validator->validate($code, $constraint);
        $this->buildViolation($constraint->reservedMessage)
            ->setParameter('{{ value }}', '"'.$code.'"')
            ->assertRaised();
    }

    public static function reservedCodes(): array
    {
        return [['api'], ['admin'], ['t'], ['_'], ['assets']];
    }

    public function testNullValueSkipped(): void
    {
        $this->validator->validate(null, new TransformationCode());
        $this->assertNoViolation();
    }

    public function testEmptyStringSkipped(): void
    {
        $this->validator->validate('', new TransformationCode());
        $this->assertNoViolation();
    }
}
