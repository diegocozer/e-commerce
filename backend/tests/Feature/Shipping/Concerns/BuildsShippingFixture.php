<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping\Concerns;

use App\Modules\Shipping\Contracts\ShippingEngine;
use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Modules\Shipping\DTOs\ShippingCustomer;
use App\Modules\Shipping\DTOs\ShippingQuoteResult;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Enums\WeightBasis;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\PostalCode\FakePostalCodeLookup;
use App\Shared\Domain\Money;
use App\Shared\Domain\PackageDimensions;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;

/**
 * SHIPPING.md §5.3 configuration: methods pickup/own-delivery/table-regional/table-cep,
 * city/range/state zones and rules (a)–(f). Ids are kept by key in $methods/$zones/$rules.
 */
trait BuildsShippingFixture
{
    /** @var array<string, ShippingMethod> */
    protected array $methods = [];

    /** @var array<string, ShippingZone> */
    protected array $zones = [];

    /** @var array<int|string, ShippingRule> */
    protected array $rules = [];

    public const string BLUMENAU = '89010100';

    public const string GASPAR = '89110000';

    public const string INDAIAL = '89130000';

    public const string JOINVILLE = '89201000';

    public const string SAO_PAULO = '01310100';

    protected function seedShippingFixture(): void
    {
        $this->methods['pickup'] = ShippingMethod::query()->create([
            'name' => 'Retirada na loja', 'code' => 'pickup-store', 'type' => ShippingMethodType::Pickup, 'position' => 0,
            'delivery_days_min' => 1, 'delivery_days_max' => 1, 'accepts_free_shipping_coupon' => false, 'weight_basis' => WeightBasis::Real,
            'pickup_street' => 'Rua XV de Novembro', 'pickup_number' => '1000', 'pickup_district' => 'Centro', 'pickup_city' => 'Blumenau',
            'pickup_state' => 'SC', 'pickup_postal_code' => '89010000', 'pickup_opening_hours' => 'Seg a Sex, 8h às 18h',
            'pickup_instructions' => 'Apresente o número do pedido.', 'is_active' => true,
        ]);
        $this->methods['own'] = $this->method('own-delivery', 'Entrega própria', ShippingMethodType::OwnDelivery, 10, 1, 2, true);
        $this->methods['regional'] = $this->method('table-regional', 'Transportadora regional', ShippingMethodType::TableRate, 20, 2, 4, true);
        $this->methods['cep'] = $this->method('table-cep', 'Frete por CEP', ShippingMethodType::TableRate, 30, 3, 6, true);

        foreach (['blumenau' => '4202404', 'gaspar' => '4205902', 'indaial' => '4207502', 'pomerode' => '4213203', 'joinville' => '4209102'] as $key => $ibge) {
            $zone = ShippingZone::query()->create(['name' => ucfirst($key), 'is_active' => true]);
            $zone->cities()->create(['city_ibge_code' => $ibge, 'city_name' => ucfirst($key), 'state' => 'SC']);
            $this->zones[$key] = $zone;
        }
        foreach (['cep_blumenau' => ['89000000', '89099999'], 'cep_floripa' => ['88000000', '88139999'], 'cep_curitiba' => ['80000000', '82999999']] as $key => [$start, $end]) {
            $zone = ShippingZone::query()->create(['name' => $key, 'is_active' => true]);
            $zone->postalRanges()->create(['start_postal_code' => $start, 'end_postal_code' => $end]);
            $this->zones[$key] = $zone;
        }
        $this->zones['sc'] = ShippingZone::query()->create(['name' => 'Santa Catarina', 'is_active' => true]);
        $this->zones['sc']->states()->create(['state' => 'SC']);

        // (b) own delivery by city + (c) free above R$ 500
        $this->rules[100] = $this->rule('own', 'blumenau', 'Entrega Blumenau', 100, ['max_weight_grams' => 100000, 'delivery_days_min' => 1, 'delivery_days_max' => 1], ShippingPriceType::Fixed, 2000);
        $this->rules[101] = $this->rule('own', 'gaspar', 'Entrega Gaspar', 100, ['max_weight_grams' => 100000, 'delivery_days_min' => 1, 'delivery_days_max' => 2], ShippingPriceType::Fixed, 3000);
        $this->rules[102] = $this->rule('own', 'indaial', 'Entrega Indaial', 100, ['max_weight_grams' => 100000, 'delivery_days_min' => 2, 'delivery_days_max' => 2], ShippingPriceType::Fixed, 3500);
        $this->rules[103] = $this->rule('own', null, 'Grátis acima de R$ 500', 10, ['min_subtotal_cents' => 50000, 'max_weight_grams' => 100000], ShippingPriceType::Free, 0);
        // (d) weight table Blumenau
        $this->rules[200] = $this->rule('regional', 'blumenau', 'Blumenau até 5 kg', 10, ['max_weight_grams' => 5000], ShippingPriceType::Fixed, 1500);
        $this->rules[201] = $this->rule('regional', 'blumenau', 'Blumenau até 10 kg', 20, ['max_weight_grams' => 10000], ShippingPriceType::Fixed, 2000);
        $this->rules[202] = $this->rule('regional', 'blumenau', 'Blumenau até 20 kg', 30, ['max_weight_grams' => 20000], ShippingPriceType::Fixed, 2800);
        // (e) other cities
        $this->rules[210] = $this->rule('regional', 'gaspar', 'Gaspar até 30 kg', 50, ['max_weight_grams' => 30000, 'delivery_days_min' => 2, 'delivery_days_max' => 3], ShippingPriceType::Fixed, 2200);
        $this->rules[213] = $this->rule('regional', 'joinville', 'Joinville até 30 kg', 50, ['max_weight_grams' => 30000, 'per_kg_cents' => 150, 'delivery_days_min' => 3, 'delivery_days_max' => 4], ShippingPriceType::FixedPlusPerKg, 2500);
        // (f) postal ranges
        $this->rules[300] = $this->rule('cep', 'cep_blumenau', 'CEP Blumenau', 100, ['max_weight_grams' => 30000], ShippingPriceType::Fixed, 1800);
        $this->rules[301] = $this->rule('cep', 'cep_floripa', 'CEP Florianópolis', 100, ['max_weight_grams' => 30000], ShippingPriceType::Fixed, 4500);
        $this->rules[302] = $this->rule('cep', 'cep_curitiba', 'CEP Curitiba', 100, ['max_weight_grams' => 20000], ShippingPriceType::Fixed, 6000);
    }

    protected function method(string $code, string $name, ShippingMethodType $type, int $position, int $min, int $max, bool $coupon, array $extra = []): ShippingMethod
    {
        return ShippingMethod::query()->create([
            'name' => $name, 'code' => $code, 'type' => $type, 'position' => $position, 'delivery_days_min' => $min,
            'delivery_days_max' => $max, 'accepts_free_shipping_coupon' => $coupon, 'weight_basis' => WeightBasis::Real,
            'handling_days' => 0, 'is_active' => true, ...$extra,
        ]);
    }

    /** @param  array<string, mixed>  $conditions */
    protected function rule(string $method, ?string $zone, string $name, int $priority, array $conditions, ShippingPriceType $type, int $price): ShippingRule
    {
        return ShippingRule::query()->create([
            'method_id' => $this->methods[$method]->id,
            'zone_id' => $zone === null ? null : $this->zones[$zone]->id,
            'name' => $name, 'priority' => $priority, 'price_type' => $type, 'price_cents' => $price,
            'per_kg_cents' => 0, 'percentage_bp' => 0, 'is_active' => true, ...$conditions,
        ]);
    }

    /** Fake carrier + one carrier method per service code. */
    protected function carrierMethod(array $settings, string $service = 'EXP', array $methodExtra = [], ?ShippingCarrier $carrier = null): ShippingMethod
    {
        $carrier ??= ShippingCarrier::query()->create([
            'name' => 'Fake Express', 'code' => 'fake_'.count($this->methods), 'driver' => 'fake', 'settings' => $settings, 'is_active' => true,
        ]);

        return $this->methods['carrier_'.count($this->methods)] = $this->method('carrier-'.strtolower($service).'-'.count($this->methods), 'Transportadora '.$service, ShippingMethodType::Carrier, 40, 3, 5, false, [
            'carrier_id' => $carrier->id, 'carrier_service_code' => $service, ...$methodExtra,
        ]);
    }

    /** UNIT line with an exact weight (1 volume 10×10×10 cm). */
    protected function unitLine(int $weightGrams, int $units = 1, bool $pickupOnly = false, int $variantId = 1): CartLineLogisticsInput
    {
        return new CartLineLogisticsInput($variantId, SaleUnit::Unit, Quantity::ofUnits($units), null, null, null, $weightGrams,
            new PackageDimensions(100, 100, 100), null, null, $pickupOnly, 'SKU-'.$variantId, 1000);
    }

    /** @param  list<CartLineLogisticsInput>|null  $lines */
    protected function shippingRequest(string $cep = self::BLUMENAU, int $weight = 7200, int $subtotal = 32000, bool $coupon = false, ?array $lines = null, ?int $cartId = null, ?int $customerId = null): ShippingRequest
    {
        return app(ShippingRequestFactory::class)->fromLines(
            $lines ?? [$this->unitLine($weight)],
            $cep,
            Money::ofCents($subtotal),
            $coupon,
            $customerId === null ? null : new ShippingCustomer($customerId, 'individual'),
            $cartId,
        );
    }

    protected function evaluate(ShippingRequest $request, bool $trace = false): ShippingQuoteResult
    {
        return app(ShippingEngine::class)->evaluate($request, $trace);
    }

    protected function postalLookup(): FakePostalCodeLookup
    {
        return app(FakePostalCodeLookup::class);
    }

    /**
     * Captures log records (MessageLogged) of every channel.
     *
     * @return \ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}>
     */
    protected function captureLogs(): \ArrayObject
    {
        $logs = new \ArrayObject;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, static function ($e) use ($logs): void {
            $logs[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });

        return $logs;
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    protected function logsNamed(\ArrayObject $logs, string $message): array
    {
        return array_values(array_filter($logs->getArrayCopy(), static fn (array $l): bool => $l['message'] === $message));
    }

    /** @return array<string, int> method code => price */
    protected function prices(ShippingQuoteResult $result): array
    {
        $prices = [];
        foreach ($result->options as $o) {
            $prices[$o->methodCode] = $o->priceCents;
        }

        return $prices;
    }

    /** @return list<string> "method_code:reason" */
    protected function unavailableCodes(ShippingQuoteResult $result): array
    {
        return array_map(static fn ($u): string => $u->methodCode.':'.$u->reason->value, $result->unavailable);
    }
}
