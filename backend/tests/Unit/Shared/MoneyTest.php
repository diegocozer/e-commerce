<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** BUSINESS_RULES.md §4.2.5 worked examples (line total = round_half_up(cents × milli / 1000)). */
    #[DataProvider('workedExamples')]
    public function test_line_totals_match_business_rules_examples(int $unitCents, string $quantity, int $expected): void
    {
        self::assertSame($expected, Money::ofCents($unitCents)->multiplyByQuantity(Quantity::fromString($quantity))->cents());
    }

    /** @return iterable<string, array{int, string, int}> */
    public static function workedExamples(): iterable
    {
        yield 'X1 vinil 5 m × R$ 15,90' => [1590, '5', 7950];
        yield 'X2 vinil 5,35 m (8506,5 → 8507)' => [1590, '5,35', 8507];
        yield 'X3 lona 3,000 m²' => [3000, '3.000', 9000];
        yield 'X4 lona 9,000 m²' => [3000, '9', 27000];
        yield 'X5 área mínima 0,500 m²' => [3000, '0.5', 1500];
        yield 'X6 backlight 6,400 m²' => [3200, '6.4', 20480];
        yield 'X7 ilhós 100 un' => [50, '100', 5000];
        yield 'X8 bobina 2 rolos' => [35000, '2', 70000];
        yield 'X9 caixa 3' => [2490, '3', 7470];
        yield 'X10 kg 1,5' => [8990, '1.5', 13485];
    }

    public function test_arithmetic_and_comparisons(): void
    {
        $a = Money::ofCents(1000);
        $b = Money::ofCents(250);

        self::assertSame(1250, $a->add($b)->cents());
        self::assertSame(750, $a->subtract($b)->cents());
        self::assertTrue($b->subtract($a)->isNegative());
        self::assertSame(3000, $a->multiply(3)->cents());
        self::assertTrue($b->lessThan($a));
        self::assertTrue(Money::zero()->isZero());
        self::assertSame(250, Money::min($a, $b)->cents());
        self::assertSame(1000, Money::max($a, $b)->cents());
        self::assertSame(1250, Money::sum([$a, $b])->cents());
        self::assertSame(1250, json_decode((string) json_encode($a->add($b))));
    }

    public function test_percentages_in_basis_points_round_half_up(): void
    {
        self::assertSame(1000, Money::ofCents(10000)->percentage(1000)->cents());
        self::assertSame(3, Money::ofCents(5)->percentage(5000)->cents()); // 2.5 → 3
        self::assertSame(1431, Money::ofCents(1590)->discountByBasisPoints(1000)->cents()); // 1431
        self::assertSame(1352, Money::ofCents(1590)->discountByBasisPoints(1500)->cents()); // 1351.5 → 1352
        $this->expectException(InvalidValue::class);
        Money::ofCents(100)->discountByBasisPoints(10001);
    }

    public function test_allocation_puts_remainder_on_last_share(): void
    {
        $shares = Money::ofCents(1000)->allocate([1, 1, 1]);

        self::assertSame([333, 333, 334], array_map(static fn (Money $m): int => $m->cents(), $shares));
    }

    public function test_formatting_brl(): void
    {
        self::assertSame('R$ 79,50', Money::ofCents(7950)->format());
        self::assertSame('R$ 1.234,05', Money::ofCents(123405)->format());
    }
}
