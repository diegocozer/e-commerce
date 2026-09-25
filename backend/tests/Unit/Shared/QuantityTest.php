<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Domain\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    #[DataProvider('validInputs')]
    public function test_parses_decimal_strings_without_floats(string $input, int $milli): void
    {
        self::assertSame($milli, Quantity::fromString($input)->milli());
    }

    /** @return iterable<array{string, int}> */
    public static function validInputs(): iterable
    {
        yield ['5', 5000];
        yield ['5.5', 5500];
        yield ['5,5', 5500];
        yield ['5.350', 5350];
        yield [' 0,001 ', 1];
        yield ['-1.25', -1250];
        yield ['123456789.999', 123456789999];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_input(string $input): void
    {
        $this->expectException(InvalidValue::class);
        Quantity::fromString($input);
    }

    /** @return iterable<array{string}> */
    public static function invalidInputs(): iterable
    {
        yield [''];
        yield ['1e3'];
        yield ['5.0001'];
        yield ['1.000,50'];
        yield ['abc'];
        yield ['NaN'];
        yield ['.5'];
        yield ['1234567890'];
    }

    public function test_from_numeric_accepts_int_float_and_string(): void
    {
        self::assertSame(5000, Quantity::fromNumeric(5)->milli());
        self::assertSame(5350, Quantity::fromNumeric(5.35)->milli());
        self::assertSame(5000, Quantity::fromNumeric(5.0)->milli());
        self::assertSame(100, Quantity::fromNumeric(0.1)->milli());
        self::assertSame(1500, Quantity::fromNumeric('1.5')->milli());

        $this->expectException(InvalidValue::class);
        Quantity::fromNumeric(1.0005);
    }

    public function test_formatting(): void
    {
        self::assertSame('5.500', Quantity::fromMilli(5500)->toDecimalString());
        self::assertSame('-0.250', Quantity::fromMilli(-250)->toDecimalString());
        self::assertSame('5.5', Quantity::fromMilli(5500)->toTrimmedString());
        self::assertSame('5', Quantity::fromMilli(5000)->toTrimmedString());
        self::assertSame('5,50', Quantity::fromMilli(5500)->format());
        self::assertSame('1.234,125', Quantity::fromMilli(1234125)->format());
        self::assertSame(5.5, Quantity::fromMilli(5500)->toNumber());
        self::assertSame(5, Quantity::fromMilli(5000)->toNumber());
        self::assertSame('"5.500"', json_encode(Quantity::fromMilli(5500)));
    }

    public function test_step_validation_rn_qtd_010(): void
    {
        $step = Quantity::fromString('0,10');

        self::assertTrue(Quantity::fromString('5,00')->isMultipleOf($step));
        self::assertFalse(Quantity::fromString('5,05')->isMultipleOf($step));
        self::assertSame('5.000', Quantity::fromString('5,05')->floorToMultipleOf($step)->toDecimalString());
        self::assertSame('5.100', Quantity::fromString('5,05')->ceilToMultipleOf($step)->toDecimalString());
        self::assertFalse(Quantity::fromString('120')->isMultipleOf(Quantity::fromString('50')));
        self::assertTrue(Quantity::fromString('1.5')->isMultipleOf(Quantity::fromString('0.5')));
    }

    public function test_arithmetic_and_comparisons(): void
    {
        $a = Quantity::fromString('2.5');
        $b = Quantity::fromString('1');

        self::assertSame(3500, $a->add($b)->milli());
        self::assertSame(1500, $a->subtract($b)->milli());
        self::assertSame(7500, $a->multiplyBy(3)->milli());
        self::assertSame(1, $a->compareTo($b));
        self::assertTrue($b->lessThan($a));
        self::assertTrue($a->greaterThanOrEqual($a));
        self::assertTrue($b->isInteger());
        self::assertFalse($a->isInteger());
        self::assertTrue(Quantity::zero()->isZero());
        self::assertTrue($a->negate()->isNegative());
        self::assertSame(2500, Quantity::max($a, $b)->milli());
        self::assertSame(1000, Quantity::min($a, $b)->milli());
    }
}
