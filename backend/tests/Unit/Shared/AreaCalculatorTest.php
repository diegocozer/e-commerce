<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\AreaCalculator;
use App\Shared\Domain\Dimensions;
use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Rounding;
use PHPUnit\Framework\TestCase;

final class AreaCalculatorTest extends TestCase
{
    public function test_x3_lona_1_20_by_2_50_is_3_m2_and_costs_9000(): void
    {
        $area = AreaCalculator::forDimensions(Dimensions::fromMeters('1,20', '2,50'), 1);

        self::assertSame(3000, $area->billableQuantity->milli());
        self::assertSame(9000, Money::ofCents(3000)->multiplyByQuantity($area->billableQuantity)->cents());
    }

    public function test_piece_area_rounds_half_up_in_thousandths(): void
    {
        // 1234 × 567 = 699 678 mm² → 699.678 → 700 (ADR-019 / DATABASE.md §3.6.3)
        self::assertSame(700, AreaCalculator::pieceArea(1234, 567)->milli());
        // 550 × 850 = 467 500 mm² → 467.5 → 468 per piece
        self::assertSame(468, AreaCalculator::pieceArea(550, 850)->milli());
        self::assertSame(1404, AreaCalculator::calculate(550, 850, 3)->stockQuantity->milli());
    }

    public function test_minimum_billable_area_applies_per_piece(): void
    {
        // X5: 0,40 × 0,50 = 0,200 m², minimum 0,500 → bills 0,500 (R$ 15,00 at R$ 30/m²)
        $area = AreaCalculator::calculate(400, 500, 1, Quantity::fromString('0.500'));
        self::assertTrue($area->minimumApplied);
        self::assertSame(200, $area->stockQuantity->milli());
        self::assertSame(500, $area->billableQuantity->milli());
        self::assertSame(1500, Money::ofCents(3000)->multiplyByQuantity($area->billableQuantity)->cents());

        // 3 pieces: minimum per piece → 3 × 0,500 = 1,500 m² billed; stock 0,600 m²
        $three = AreaCalculator::calculate(400, 500, 3, Quantity::fromString('0.500'));
        self::assertSame(1500, $three->billableQuantity->milli());
        self::assertSame(600, $three->stockQuantity->milli());
    }

    public function test_minimum_not_applied_when_piece_is_larger(): void
    {
        $area = AreaCalculator::calculate(1200, 2500, 3, Quantity::fromString('1.000'));

        self::assertFalse($area->minimumApplied);
        self::assertSame(9000, $area->billableQuantity->milli());
        self::assertSame(9000, $area->stockQuantity->milli());
    }

    public function test_rejects_non_positive_input(): void
    {
        $this->expectException(InvalidValue::class);
        AreaCalculator::calculate(100, 100, 0);
    }

    public function test_rounding_helpers(): void
    {
        self::assertSame(3, Rounding::halfUpDiv(5, 2));
        self::assertSame(-3, Rounding::halfUpDiv(-5, 2));
        self::assertSame(2, Rounding::halfUpDiv(7, 4)); // 1.75 → 2
        self::assertSame(1, Rounding::halfUpDiv(5, 4)); // 1.25 → 1
        self::assertSame(8, Rounding::ceilDiv(7200, 1000));
        self::assertSame(7, Rounding::ceilDiv(7000, 1000));
    }
}
