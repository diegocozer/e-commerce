<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Modules\Shipping\Enums\FreeShippingReason;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Enums\WeightBasis;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Support\FakeMonotonicClock;
use App\Modules\Shipping\Support\MonotonicClock;
use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Shipping\Concerns\BuildsShippingFixture;
use Tests\TestCase;

/** SHIPPING.md §13 — engine-level cases (T01–T26, T34–T36, T42, T47–T51). */
final class ShippingEngineMatrixTest extends TestCase
{
    use BuildsShippingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedShippingFixture();
    }

    public function test_t01_valid_cep_with_mask_is_normalized_and_returns_local_options(): void
    {
        $result = $this->evaluate($this->shippingRequest('89010-100'));

        self::assertSame('89010100', $result->destination->postalCode);
        self::assertSame('4202404', $result->destination->cityIbgeCode);
        self::assertSame(['pickup-store' => 0, 'table-cep' => 1800, 'own-delivery' => 2000, 'table-regional' => 2000], $this->prices($result));
        self::assertSame(['1:pickup'], [str_replace((string) $this->methods['pickup']->id, '1', $result->options[0]->optionId)]);
        self::assertSame($this->methods['own']->id.':'.$this->rules[100]->id, $result->option($this->methods['own']->id.':'.$this->rules[100]->id)?->optionId);
    }

    /** @return array<string, array{string}> */
    public static function invalidCeps(): array
    {
        return ['short (T02)' => ['8901010'], 'letters (T03)' => ['8901A100'], 'zeros (T03)' => ['00000000'], 'long' => ['890101000']];
    }

    #[DataProvider('invalidCeps')]
    public function test_t02_t03_invalid_cep_is_422(string $cep): void
    {
        try {
            $this->shippingRequest($cep);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['postal_code' => ['CEP inválido.']], $e->errors());
        }
    }

    public function test_t04_uncovered_region_only_offers_pickup(): void
    {
        $result = $this->evaluate($this->shippingRequest(self::SAO_PAULO));

        self::assertSame(['pickup-store' => 0], $this->prices($result));
        self::assertContains('own-delivery:out_of_coverage', $this->unavailableCodes($result));
        self::assertContains('table-regional:out_of_coverage', $this->unavailableCodes($result));
        self::assertContains('table-cep:out_of_coverage', $this->unavailableCodes($result));
    }

    public function test_t05_no_option_at_all_when_pickup_is_inactive(): void
    {
        $this->methods['pickup']->update(['is_active' => false]);

        self::assertSame([], $this->evaluate($this->shippingRequest(self::SAO_PAULO))->options);
    }

    public function test_t06_t07_t08_weight_limits_are_inclusive(): void
    {
        $regional = fn (int $g) => $this->evaluate($this->shippingRequest(weight: $g));

        self::assertSame(1500, $this->prices($regional(5000))['table-regional']);           // T06
        self::assertSame($this->rules[200]->id, $regional(5000)->options[1]->ruleId ?? $this->findRule($regional(5000), 'table-regional'));
        self::assertSame(2000, $this->prices($regional(5001))['table-regional']);           // T07
        self::assertSame(2800, $this->prices($regional(20000))['table-regional']);
        $above = $regional(20001);                                                          // T08
        self::assertArrayNotHasKey('table-regional', $this->prices($above));
        self::assertContains('table-regional:weight_above_limit', $this->unavailableCodes($above));
    }

    public function test_t09_t10_free_shipping_threshold_499_99_vs_500_00(): void
    {
        $below = $this->option($this->evaluate($this->shippingRequest(subtotal: 49999)), 'own-delivery');
        self::assertSame(2000, $below->priceCents);
        self::assertFalse($below->isFree);

        $at = $this->option($this->evaluate($this->shippingRequest(subtotal: 50000)), 'own-delivery');
        self::assertSame(0, $at->priceCents);
        self::assertTrue($at->isFree);
        self::assertSame(FreeShippingReason::Rule, $at->freeReason);
        self::assertSame(2000, $at->originalPriceCents);
        self::assertSame($this->rules[103]->id, $at->ruleId);
    }

    public function test_t11_free_rule_does_not_leak_coverage(): void
    {
        $result = $this->evaluate($this->shippingRequest(self::SAO_PAULO, subtotal: 50000));

        self::assertContains('own-delivery:out_of_coverage', $this->unavailableCodes($result));
    }

    public function test_t12_own_delivery_by_city(): void
    {
        self::assertSame(3000, $this->prices($this->evaluate($this->shippingRequest(self::GASPAR)))['own-delivery']);
        self::assertSame(3500, $this->prices($this->evaluate($this->shippingRequest(self::INDAIAL)))['own-delivery']);
    }

    public function test_t13_pickup_uses_method_address_and_label(): void
    {
        $pickup = $this->option($this->evaluate($this->shippingRequest(self::SAO_PAULO)), 'pickup-store');

        self::assertSame(0, $pickup->priceCents);
        self::assertTrue($pickup->isFree);
        self::assertSame('Disponível em 1 dia útil após o pagamento', $pickup->deliveryLabel);
        self::assertSame('Rua XV de Novembro', $pickup->pickupAddress?->street);
        self::assertSame('Seg a Sex, 8h às 18h', $pickup->pickupAddress?->openingHours);
        self::assertSame($this->methods['pickup']->id.':pickup', $pickup->optionId);
    }

    public function test_t14_pickup_only_item_only_offers_pickup(): void
    {
        $result = $this->evaluate($this->shippingRequest(lines: [$this->unitLine(1000), $this->unitLine(500, pickupOnly: true, variantId: 2)]));

        self::assertSame(['pickup-store' => 0], $this->prices($result));
        self::assertContains('own-delivery:pickup_only_items', $this->unavailableCodes($result));
    }

    public function test_t15_carrier_ok_adds_handling_days_and_carrier_info(): void
    {
        $method = $this->carrierMethod(['mode' => 'ok', 'services' => [['code' => 'EXP', 'name' => 'Expresso', 'price_cents' => 3990, 'days_min' => 3, 'days_max' => 5]]], 'EXP', ['handling_days' => 1]);
        $option = $this->option($this->evaluate($this->shippingRequest()), $method->code);

        self::assertSame(3990, $option->priceCents);
        self::assertSame([4, 6], [$option->deliveryDaysMin, $option->deliveryDaysMax]);
        self::assertSame('EXP', $option->carrier?->serviceCode);
        self::assertSame($method->id.':EXP', $option->optionId);
    }

    public function test_t16_carrier_timeout_is_omitted_and_logged(): void
    {
        $logs = $this->captureLogs();
        $method = $this->carrierMethod(['mode' => 'timeout']);
        $result = $this->evaluate($this->shippingRequest());

        self::assertContains($method->code.':carrier_timeout', $this->unavailableCodes($result));
        self::assertArrayHasKey('own-delivery', $this->prices($result));
        $failed = $this->logsNamed($logs, 'shipping.carrier.failed');
        self::assertSame(['warning', 'timeout'], [$failed[0]['level'], $failed[0]['context']['reason']]);
    }

    public function test_t17_carrier_error_is_omitted_and_logged_as_error(): void
    {
        $logs = $this->captureLogs();
        $method = $this->carrierMethod(['mode' => 'error']);
        $result = $this->evaluate($this->shippingRequest());

        self::assertContains($method->code.':carrier_error', $this->unavailableCodes($result));
        self::assertCount(4, $result->options);
        $failed = $this->logsNamed($logs, 'shipping.carrier.failed');
        self::assertSame(['error', 'error'], [$failed[0]['level'], $failed[0]['context']['reason']]);
    }

    public function test_t18_missing_service_code(): void
    {
        $method = $this->carrierMethod(['mode' => 'ok'], 'SEDEX');

        self::assertContains($method->code.':carrier_service_missing', $this->unavailableCodes($this->evaluate($this->shippingRequest())));
    }

    public function test_t19_unregistered_driver(): void
    {
        $carrier = ShippingCarrier::query()->create(['name' => 'X', 'code' => 'xyz', 'driver' => 'xyz', 'settings' => [], 'is_active' => true]);
        $method = $this->carrierMethod([], 'EXP', [], $carrier);

        self::assertContains($method->code.':carrier_not_registered', $this->unavailableCodes($this->evaluate($this->shippingRequest())));
    }

    public function test_inactive_carrier(): void
    {
        $carrier = ShippingCarrier::query()->create(['name' => 'X', 'code' => 'off', 'driver' => 'fake', 'settings' => [], 'is_active' => false]);
        $method = $this->carrierMethod([], 'EXP', [], $carrier);

        self::assertContains($method->code.':carrier_inactive', $this->unavailableCodes($this->evaluate($this->shippingRequest())));
    }

    public function test_t20_carrier_is_quoted_once_for_two_methods(): void
    {
        $clock = new FakeMonotonicClock;
        $this->app->instance(MonotonicClock::class, $clock);
        $settings = ['mode' => 'ok', 'delay_ms' => 1000, 'services' => [
            ['code' => 'PAC', 'name' => 'PAC', 'price_cents' => 2500, 'days_min' => 5, 'days_max' => 8],
            ['code' => 'SEDEX', 'name' => 'SEDEX', 'price_cents' => 4500, 'days_min' => 1, 'days_max' => 2],
        ]];
        $pac = $this->carrierMethod($settings, 'PAC');
        $sedex = $this->carrierMethod([], 'SEDEX', [], $pac->carrier);

        $result = $this->evaluate($this->shippingRequest());

        self::assertSame(2500, $this->prices($result)[$pac->code]);
        self::assertSame(4500, $this->prices($result)[$sedex->code]);
        self::assertSame(1000, $clock->nowMs()); // one call (1 s of virtual delay)
    }

    public function test_t21_fixed_plus_per_kg_joinville(): void
    {
        self::assertSame(3700, $this->prices($this->evaluate($this->shippingRequest(self::JOINVILLE, 7200)))['table-regional']);
    }

    public function test_t22_same_priority_more_specific_zone_wins(): void
    {
        $r220 = $this->rule('regional', 'cep_blumenau', 'Faixa Blumenau', 20, [], ShippingPriceType::Fixed, 1700);
        $option = $this->option($this->evaluate($this->shippingRequest(weight: 7000)), 'table-regional');

        self::assertSame(1700, $option->priceCents);
        self::assertSame($r220->id, $option->ruleId);
    }

    public function test_t23_full_tie_lowest_id_wins_with_warning(): void
    {
        $twin = $this->rule('regional', 'blumenau', 'Gêmea', 20, ['max_weight_grams' => 10000], ShippingPriceType::Fixed, 1900);
        $result = $this->evaluate($this->shippingRequest(weight: 7000), true);

        self::assertSame($this->rules[201]->id, $this->option($result, 'table-regional')->ruleId);
        self::assertLessThan($twin->id, $this->rules[201]->id);
        $trace = collect($result->trace?->toArray()['methods'])->firstWhere('code', 'table-regional');
        self::assertContains('tie_broken_by_id', $trace['warnings']);
    }

    public function test_t24_priority_beats_specificity(): void
    {
        $global = $this->rule('regional', null, 'Global prioritária', 5, [], ShippingPriceType::Fixed, 999);

        self::assertSame($global->id, $this->option($this->evaluate($this->shippingRequest()), 'table-regional')->ruleId);
    }

    public function test_t25_coupon_zeroes_own_and_table_methods_only(): void
    {
        $carrier = $this->carrierMethod(['mode' => 'ok']);
        $result = $this->evaluate($this->shippingRequest(coupon: true));

        foreach (['own-delivery' => 2000, 'table-regional' => 2000, 'table-cep' => 1800] as $code => $original) {
            $o = $this->option($result, $code);
            self::assertSame(0, $o->priceCents);
            self::assertSame($original, $o->originalPriceCents);
            self::assertSame(FreeShippingReason::Coupon, $o->freeReason);
            self::assertSame($original, $o->couponDiscountCents());
        }
        self::assertSame(3990, $this->option($result, $carrier->code)->priceCents);
        self::assertNull($this->option($result, 'pickup-store')->freeReason);
    }

    public function test_coupon_keeps_rule_reason_when_already_free(): void
    {
        $o = $this->option($this->evaluate($this->shippingRequest(subtotal: 50000, coupon: true)), 'own-delivery');

        self::assertSame(FreeShippingReason::Rule, $o->freeReason);
    }

    public function test_t26_method_not_accepting_coupon_keeps_price(): void
    {
        $this->methods['regional']->update(['accepts_free_shipping_coupon' => false]);

        self::assertSame(2000, $this->prices($this->evaluate($this->shippingRequest(coupon: true)))['table-regional']);
    }

    public function test_t34_t35_lookup_failure_keeps_ranges_and_marks_city_zones_unresolved(): void
    {
        $this->postalLookup()->failFor(self::BLUMENAU);
        $result = $this->evaluate($this->shippingRequest());

        self::assertFalse($result->destination->resolved);
        self::assertSame(1800, $this->prices($result)['table-cep']);                          // T34
        self::assertContains('own-delivery:destination_unresolved', $this->unavailableCodes($result)); // T35
        self::assertContains('table-regional:destination_unresolved', $this->unavailableCodes($result));
        self::assertArrayHasKey('pickup-store', $this->prices($result));
    }

    public function test_t36_state_zone_matches_through_cep_range_fallback(): void
    {
        $method = $this->method('table-sc', 'SC', ShippingMethodType::TableRate, 50, 3, 6, true);
        $this->methods['sc'] = $method;
        $this->rule('sc', 'sc', 'SC', 100, [], ShippingPriceType::Fixed, 3500);
        $this->postalLookup()->notFound('89999000');

        $result = $this->evaluate($this->shippingRequest('89999000'));

        self::assertSame('cep_range', $result->destination->stateSource);
        self::assertSame('SC', $result->destination->state);
        self::assertSame(3500, $this->prices($result)['table-sc']);
    }

    public function test_t42_chargeable_weight_uses_cubic_weight(): void
    {
        $this->methods['regional']->update(['weight_basis' => WeightBasis::Chargeable]);
        // 5 kg real, 60 000 cm³ (one 100×30×20 cm box) ⇒ cubic 10 000 g ⇒ "até 10 kg"
        $line = new CartLineLogisticsInput(1, SaleUnit::Unit, Quantity::ofUnits(1), null, null, null, 5000, new PackageDimensions(1000, 300, 200), null, null, false);
        $option = $this->option($this->evaluate($this->shippingRequest(lines: [$line])), 'table-regional');

        self::assertSame($this->rules[201]->id, $option->ruleId);
        self::assertSame(2000, $option->priceCents);
    }

    public function test_t47_missing_logistics_data_only_pickup_and_logs(): void
    {
        $logs = $this->captureLogs();
        $result = $this->evaluate($this->shippingRequest(lines: [$this->unitLine(0)]));

        self::assertSame(['pickup-store' => 0], $this->prices($result));
        self::assertContains('own-delivery:logistics_data_missing', $this->unavailableCodes($result));
        self::assertSame([1], $this->logsNamed($logs, 'shipping.logistics.missing_data')[0]['context']['variant_ids']);
    }

    public function test_t48_rule_validity_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
        $this->rules[200]->update(['valid_until' => '2026-10-01T12:00:00Z']); // exclusive ⇒ expired now
        self::assertSame(2000, $this->prices($this->evaluate($this->shippingRequest(weight: 1000)))['table-regional']);

        $this->rules[200]->update(['valid_until' => null, 'valid_from' => '2026-10-02T00:00:00Z']);
        self::assertSame(2000, $this->prices($this->evaluate($this->shippingRequest(weight: 1000)))['table-regional']);

        $this->rules[200]->update(['valid_from' => '2026-10-01T12:00:00Z']); // inclusive
        self::assertSame(1500, $this->prices($this->evaluate($this->shippingRequest(weight: 1000)))['table-regional']);
    }

    public function test_t49_sorting_ties_by_max_days_then_position(): void
    {
        $result = $this->evaluate($this->shippingRequest());
        // own-delivery (2000, 1/1) before table-regional (2000, 2/4)
        $codes = array_map(fn ($o) => $o->methodCode, $result->options);
        self::assertSame(['pickup-store', 'table-cep', 'own-delivery', 'table-regional'], $codes);

        $this->rules[100]->update(['delivery_days_min' => 2, 'delivery_days_max' => 4]);
        $codes = array_map(fn ($o) => $o->methodCode, $this->evaluate($this->shippingRequest())->options);
        self::assertSame(['pickup-store', 'table-cep', 'own-delivery', 'table-regional'], $codes); // same days ⇒ position
    }

    public function test_t50_deterministic(): void
    {
        $a = $this->evaluate($this->shippingRequest());
        $b = $this->evaluate($this->shippingRequest());

        self::assertEquals($a->options, $b->options);
    }

    public function test_t51_carrier_time_budget(): void
    {
        config(['shipping.carrier_total_budget_ms' => 5000]);
        $this->app->instance(MonotonicClock::class, new FakeMonotonicClock);
        $a = $this->carrierMethod(['mode' => 'ok', 'delay_ms' => 3000]);
        $b = $this->carrierMethod(['mode' => 'ok', 'delay_ms' => 3000]);
        $c = $this->carrierMethod(['mode' => 'ok', 'delay_ms' => 3000]);

        $result = $this->evaluate($this->shippingRequest());

        self::assertArrayHasKey($a->code, $this->prices($result));
        self::assertArrayHasKey($b->code, $this->prices($result));
        self::assertContains($c->code.':carrier_budget_exceeded', $this->unavailableCodes($result));
    }

    public function test_volume_and_length_limits(): void
    {
        $this->rules[300]->update(['max_package_length_cm' => '5.0']);
        self::assertContains('table-cep:volume_above_limit', $this->unavailableCodes($this->evaluate($this->shippingRequest(weight: 1000))));
    }

    public function test_simulator_trace_lists_rejections(): void
    {
        $trace = $this->evaluate($this->shippingRequest(weight: 7200), true)->trace?->toArray();
        $regional = collect($trace['methods'])->firstWhere('code', 'table-regional');

        self::assertSame('option', $regional['status']);
        $r200 = collect($regional['rules'])->firstWhere('rule_id', $this->rules[200]->id);
        self::assertSame('rejected', $r200['result']);
        self::assertSame('weight_above_max', $r200['reasons'][0]['code']);
        self::assertSame('not_evaluated', collect($regional['rules'])->firstWhere('rule_id', $this->rules[202]->id)['result']);
        self::assertSame('zone_not_matched', collect($regional['rules'])->firstWhere('rule_id', $this->rules[210]->id)['result']);
        self::assertSame($this->rules[201]->id, $regional['winner_rule_id']);
        self::assertContains('city:4202404', array_column($trace['zones_matched'], 'matched_by'));
    }

    private function option($result, string $code)
    {
        foreach ($result->options as $o) {
            if ($o->methodCode === $code) {
                return $o;
            }
        }
        self::fail("No option for {$code}: ".implode(',', $this->unavailableCodes($result)));
    }

    private function findRule($result, string $code): ?int
    {
        return $this->option($result, $code)->ruleId;
    }
}
