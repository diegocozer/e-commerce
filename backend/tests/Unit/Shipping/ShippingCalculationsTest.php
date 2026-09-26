<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Modules\Shipping\Delivery\WeekendBusinessDayCalculator;
use App\Modules\Shipping\Domain\Config\RuleConfig;
use App\Modules\Shipping\Domain\Logistics\CartLogisticsCalculator;
use App\Modules\Shipping\Domain\Logistics\Dimension;
use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Modules\Shipping\DTOs\CartLogistics;
use App\Modules\Shipping\DTOs\Destination;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\DeliveryLabelFormatter;
use App\Modules\Shipping\Engine\RuleEvaluator;
use App\Modules\Shipping\Engine\RulePriceCalculator;
use App\Modules\Shipping\Enums\RejectionCode;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Enums\WeightBasis;
use App\Modules\Shipping\PostalCode\PostalCodeNormalizer;
use App\Modules\Shipping\PostalCode\PostalCodeStateResolver;
use App\Modules\Shipping\Quotes\QuoteHasher;
use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SHIPPING.md §13 T39–T46 + ZoneMatcher/RuleEvaluator/labels/business days (UT-SHP-01…08). */
final class ShippingCalculationsTest extends TestCase
{
    private static function rule(ShippingPriceType $type, array $o = []): RuleConfig
    {
        return new RuleConfig(
            $o['id'] ?? 1, 1, null, 'r', 100,
            $o['min_weight'] ?? null, $o['max_weight'] ?? null, $o['min_subtotal'] ?? null, $o['max_subtotal'] ?? null,
            null, $o['max_volume'] ?? null, $o['max_length_mm'] ?? null,
            $type, $o['price'] ?? 0, $o['per_kg'] ?? 0, $o['bp'] ?? 0, $o['min_price'] ?? null, $o['max_price'] ?? null,
            null, null, $o['active'] ?? true, $o['from'] ?? null, $o['until'] ?? null,
        );
    }

    /** @return array<string, array{int, int}> */
    public static function perKgCases(): array
    {
        return ['1 g' => [1, 150], '1000 g' => [1000, 150], '1001 g' => [1001, 300], '5000 g' => [5000, 750], '5001 g' => [5001, 900]];
    }

    #[DataProvider('perKgCases')]
    public function test_t39_per_kg_charges_started_kg(int $grams, int $expected): void
    {
        self::assertSame($expected, (new RulePriceCalculator)->price(self::rule(ShippingPriceType::PerKg, ['per_kg' => 150]), $grams, 0));
    }

    public function test_t40_percentage_half_up(): void
    {
        self::assertSame(1235, (new RulePriceCalculator)->price(self::rule(ShippingPriceType::PercentageOfSubtotal, ['bp' => 1000]), 0, 12345));
    }

    public function test_t41_min_and_max_price(): void
    {
        $calc = new RulePriceCalculator;
        self::assertSame(1500, $calc->price(self::rule(ShippingPriceType::PerKg, ['per_kg' => 100, 'min_price' => 1500]), 3000, 0));
        self::assertSame(500, $calc->price(self::rule(ShippingPriceType::Fixed, ['price' => 900, 'max_price' => 500]), 3000, 0));
        self::assertSame(0, $calc->price(self::rule(ShippingPriceType::Free, ['min_price' => 1500]), 3000, 0));
        self::assertSame(3700, $calc->price(self::rule(ShippingPriceType::FixedPlusPerKg, ['price' => 2500, 'per_kg' => 150]), 7200, 0));
    }

    public function test_rule_evaluator_inclusive_limits_and_all_reasons(): void
    {
        $evaluator = new RuleEvaluator;
        $request = self::request(subtotal: 50000);
        $now = new \DateTimeImmutable('2026-10-01T12:00:00Z');

        self::assertTrue($evaluator->evaluate(self::rule(ShippingPriceType::Fixed, ['max_weight' => 5000, 'min_subtotal' => 50000]), $request, 5000, $now)->matched);
        $rejected = $evaluator->evaluate(self::rule(ShippingPriceType::Fixed, ['max_weight' => 5000, 'min_subtotal' => 50001, 'until' => $now]), $request, 5001, $now);
        self::assertFalse($rejected->matched);
        self::assertSame([RejectionCode::Expired, RejectionCode::WeightAboveMax, RejectionCode::SubtotalBelowMin], $rejected->codes());
        self::assertTrue($rejected->isTemporal());
        self::assertSame([RejectionCode::LengthAboveMax], $evaluator->evaluate(self::rule(ShippingPriceType::Fixed, ['max_length_mm' => 1000]), $request, 1, $now)->codes());
    }

    public function test_t43_linear_meter(): void
    {
        $l = self::calc()->calculate([new CartLineLogisticsInput(1, SaleUnit::LinearMeter, Quantity::fromMilli(5500), null, null, null, 180, new PackageDimensions(1300, 100, 100), null, 1220, false)]);

        self::assertSame(990, $l->totalWeightGrams);
        self::assertSame(1, $l->volumesCount);
        self::assertSame([1320, 100, 100], [$l->packages[0]->lengthMm, $l->packages[0]->widthMm, $l->packages[0]->heightMm]);
        self::assertSame(13200, $l->totalVolumeCm3);
        self::assertSame(1320, $l->largestDimensionMm);
    }

    public function test_t44_square_meter_uses_real_area_adr_030(): void
    {
        // 0,3 × 0,3 m, billable 1 m² (minimum), 250 g/m² ⇒ ADR-030: real area 0,09 m² ⇒ ceil(22,5) = 23 g
        $l = self::calc()->calculate([new CartLineLogisticsInput(1, SaleUnit::SquareMeter, Quantity::fromMilli(1000), 300, 300, 1, 250, new PackageDimensions(1300, 100, 100), null, null, false)]);

        self::assertSame(23, $l->totalWeightGrams);
        self::assertSame(400, $l->packages[0]->lengthMm); // 30 + 10 cm
    }

    public function test_square_meter_rolls_by_smaller_side(): void
    {
        $l = self::calc()->calculate([new CartLineLogisticsInput(1, SaleUnit::SquareMeter, Quantity::fromMilli(6100), 1220, 2500, 2, 400, new PackageDimensions(1300, 120, 120), null, 1220, false)]);

        self::assertSame(1320, $l->packages[0]->lengthMm);
        self::assertSame(2440, $l->totalWeightGrams); // 1,22 × 2,5 × 2 = 6,1 m² × 400 g
    }

    public function test_t45_kg(): void
    {
        $l = self::calc()->calculate([new CartLineLogisticsInput(1, SaleUnit::Kg, Quantity::fromMilli(1250), null, null, null, 0, new PackageDimensions(200, 200, 200), null, null, false)]);

        self::assertSame(1250, $l->totalWeightGrams);
        self::assertFalse($l->missingData);
    }

    public function test_t46_unit_volumes_and_weight_split(): void
    {
        $l = self::calc()->calculate([new CartLineLogisticsInput(1, SaleUnit::Unit, Quantity::ofUnits(3), null, null, null, 400, new PackageDimensions(300, 200, 100), null, null, false)]);

        self::assertSame(1200, $l->totalWeightGrams);
        self::assertSame(3, $l->volumesCount);
        self::assertSame(18000, $l->totalVolumeCm3);

        $box = self::calc()->calculate([new CartLineLogisticsInput(2, SaleUnit::Box, Quantity::ofUnits(5), null, null, null, 333, new PackageDimensions(100, 100, 100), 2, null, false)]);
        self::assertSame(3, $box->volumesCount);
        self::assertSame(1665, array_sum(array_map(fn ($p) => $p->weightGrams, $box->packages)));
        self::assertSame(555, $box->packages[0]->weightGrams);
    }

    public function test_missing_data_is_flagged_never_invented(): void
    {
        $l = self::calc()->calculate([
            new CartLineLogisticsInput(7, SaleUnit::Unit, Quantity::ofUnits(1), null, null, null, 0, new PackageDimensions(1, 1, 1), null, null, false),
            new CartLineLogisticsInput(8, SaleUnit::LinearMeter, Quantity::ofUnits(1), null, null, null, 100, null, null, 1220, true),
        ]);

        self::assertTrue($l->missingData);
        self::assertSame([7, 8], $l->variantsMissingData);
        self::assertTrue($l->hasPickupOnlyItems);
        self::assertSame(0, $l->totalWeightGrams);
    }

    public function test_cubic_and_chargeable_weight(): void
    {
        $l = new CartLogistics([], [], 5000, 60000, 1, 1000, false, false);

        self::assertSame(10000, $l->cubicWeightGrams(6000));
        self::assertSame(10000, $l->effectiveWeightGrams(WeightBasis::Chargeable, 6000));
        self::assertSame(5000, $l->effectiveWeightGrams(WeightBasis::Real, 6000));
        self::assertSame(1, (new CartLogistics([], [], 0, 1, 1, 1, false, false))->cubicWeightGrams(6000));
    }

    public function test_dimension_conversion_without_float(): void
    {
        self::assertSame(125, Dimension::cmToMm('12.5'));
        self::assertSame(1300, Dimension::cmToMm('130'));
        self::assertSame(1320, Dimension::cmToMm('132.0'));
        self::assertSame('132.5', Dimension::mmToCm(1325));
    }

    public function test_delivery_labels(): void
    {
        $f = new DeliveryLabelFormatter;
        self::assertSame('1 dia útil', $f->delivery(1, 1));
        self::assertSame('2 dias úteis', $f->delivery(2, 2));
        self::assertSame('2 a 3 dias úteis', $f->delivery(2, 3));
        self::assertSame('Entrega no mesmo dia útil', $f->delivery(0, 0));
        self::assertSame('Disponível no mesmo dia útil após o pagamento', $f->pickup(0, 0));
        self::assertSame('Disponível em 1 dia útil após o pagamento', $f->pickup(1, 1));
        self::assertSame('Disponível em 3 dias úteis após o pagamento', $f->pickup(3, 3));
    }

    public function test_business_days_skip_weekends_and_respect_cutoff(): void
    {
        $c = new WeekendBusinessDayCalculator('America/Sao_Paulo', '14:00');
        // Fri 2026-09-25 10:00 BRT (13:00Z) + 1 ⇒ Mon 28
        self::assertSame('2026-09-28', $c->addBusinessDays(new \DateTimeImmutable('2026-09-25T13:00:00Z'), 1)->format('Y-m-d'));
        // Fri 15:00 BRT (after cut-off) + 1 ⇒ starts Mon ⇒ Tue 29
        self::assertSame('2026-09-29', $c->addBusinessDays(new \DateTimeImmutable('2026-09-25T18:00:00Z'), 1)->format('Y-m-d'));
        // Sat + 2 ⇒ starts Mon ⇒ Wed 30
        self::assertSame('2026-09-30', $c->addBusinessDays(new \DateTimeImmutable('2026-09-26T13:00:00Z'), 2)->format('Y-m-d'));
        // Wed 10:00 + 0 ⇒ same day
        self::assertSame('2026-09-30', $c->addBusinessDays(new \DateTimeImmutable('2026-09-30T13:00:00Z'), 0)->format('Y-m-d'));
    }

    public function test_postal_code_normalizer_and_state_fallback(): void
    {
        self::assertSame('89010100', PostalCodeNormalizer::tryNormalize('89010-100'));
        self::assertSame('89010100', PostalCodeNormalizer::tryNormalize('89.010-100'));
        self::assertNull(PostalCodeNormalizer::tryNormalize('8901010'));
        self::assertNull(PostalCodeNormalizer::tryNormalize('8901A100'));
        self::assertNull(PostalCodeNormalizer::tryNormalize('00000000'));

        $s = new PostalCodeStateResolver;
        self::assertSame('SC', $s->stateFor('89010100'));
        self::assertSame('SP', $s->stateFor('01310100'));
        self::assertSame('RR', $s->stateFor('69301000'));
        self::assertSame('DF', $s->stateFor('73000000'));
        self::assertNull($s->stateFor('00500000'));
    }

    public function test_quote_hash_is_canonical(): void
    {
        $h = new QuoteHasher;
        $a = $h->hash(self::request(), [['variant_id' => 2, 'quantity_milli' => 1000, 'width_mm' => null, 'height_mm' => null, 'pieces' => null], ['variant_id' => 1, 'quantity_milli' => 5500, 'width_mm' => null, 'height_mm' => null, 'pieces' => null]]);
        $b = $h->hash(self::request(), [['variant_id' => 1, 'quantity_milli' => 5500, 'width_mm' => null, 'height_mm' => null, 'pieces' => null], ['pieces' => null, 'height_mm' => null, 'width_mm' => null, 'quantity_milli' => 1000, 'variant_id' => 2]]);
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a));
        self::assertNotSame($a, $h->hash(self::request(subtotal: 1), [['variant_id' => 1, 'quantity_milli' => 5500]]));
    }

    private static function calc(): CartLogisticsCalculator
    {
        return new CartLogisticsCalculator(100);
    }

    private static function request(int $subtotal = 32000): ShippingRequest
    {
        return new ShippingRequest(
            new Destination('89010100', '4202404', 'Blumenau', 'SC', true, 'lookup'),
            new CartLogistics([], [], 1000, 1000, 1, 2000, false, false),
            $subtotal,
            false,
        );
    }
}
