<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\Exceptions\InvalidValue;
use App\Shared\Domain\Length;
use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use App\Shared\Domain\Weight;
use PHPUnit\Framework\TestCase;

final class MeasuresTest extends TestCase
{
    public function test_length_converts_meters_strings_to_mm(): void
    {
        self::assertSame(1220, Length::fromMeters('1,22')->millimeters());
        self::assertSame(1220, Length::fromMeters('1.220')->millimeters());
        self::assertSame(3000, Length::fromMeters('3')->millimeters());
        self::assertSame('1.220', Length::fromMillimeters(1220)->toMetersString());
        self::assertFalse(Length::fromMeters('1,205')->isWholeCentimeters());

        $this->expectException(InvalidValue::class);
        Length::fromMeters('1.2345');
    }

    public function test_weight(): void
    {
        self::assertSame(1500, Weight::fromKilograms('1,5')->grams());
        // 250 g/m × 5,35 m = 1337.5 → ceil 1338 g
        self::assertSame(1338, Weight::fromGrams(250)->multiplyByQuantity(Quantity::fromString('5.35'))->grams());
        self::assertSame(8, Weight::fromGrams(7200)->chargeableKilograms());
        self::assertSame(1, Weight::fromGrams(0)->chargeableKilograms());
        self::assertSame(300, Weight::fromGrams(100)->add(Weight::fromGrams(200))->grams());
    }

    public function test_package_dimensions(): void
    {
        $package = PackageDimensions::fromCentimeters('105', '3', '3,5');

        self::assertSame(1103, $package->volumeCm3()); // 105 × 3 × 3.5 = 1102.5 → 1103
        self::assertSame(1050, $package->longestSideMm());
    }

    public function test_sale_unit_metadata(): void
    {
        self::assertSame('Metro linear', SaleUnit::LinearMeter->label());
        self::assertSame('m²', SaleUnit::SquareMeter->abbreviation());
        self::assertSame('/cx', SaleUnit::Box->priceSuffix());
        self::assertTrue(SaleUnit::Kg->allowsFraction());
        self::assertTrue(SaleUnit::LinearMeter->allowsFraction());
        self::assertFalse(SaleUnit::Unit->allowsFraction());
        self::assertFalse(SaleUnit::SquareMeter->allowsFraction());
        self::assertTrue(SaleUnit::SquareMeter->usesDimensions());
        self::assertCount(6, SaleUnit::options());
    }
}
