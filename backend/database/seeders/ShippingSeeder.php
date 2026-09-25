<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Enums\WeightBasis;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** DATABASE.md §7.8 / SHIPPING.md §5.3 — carrier placeholder, methods, zones and rules. */
class ShippingSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $zoneIds = [];

    /** @var array<string, int> */
    private array $methodIds = [];

    public function run(SettingsRepository $settings): void
    {
        DB::transaction(function () use ($settings): void {
            ShippingCarrier::query()->updateOrCreate(['code' => 'correios'], [
                'name' => 'Correios', 'driver' => 'correios', 'settings' => [], 'is_active' => false,
            ]);

            $this->seedMethods($settings->array(SettingKey::StoreAddress));
            $this->seedZones();
            $this->seedRules();
        });
    }

    /** @param  array<string, mixed>  $store */
    private function seedMethods(array $store): void
    {
        $methods = [
            'pickup-store' => [
                'name' => 'Retirada na empresa', 'type' => ShippingMethodType::Pickup, 'delivery_days_min' => 1, 'delivery_days_max' => 1,
                'position' => 0, 'accepts_free_shipping_coupon' => false, 'weight_basis' => WeightBasis::Real,
                'pickup_street' => $store['street'] ?? 'Rua XV de Novembro', 'pickup_number' => $store['number'] ?? '1000',
                'pickup_district' => $store['district'] ?? 'Centro', 'pickup_city' => $store['city'] ?? 'Blumenau',
                'pickup_state' => $store['state'] ?? 'SC', 'pickup_postal_code' => $store['postal_code'] ?? '89010001',
                'pickup_opening_hours' => 'Seg–Sex 8h–18h', 'pickup_instructions' => 'Apresente o número do pedido',
            ],
            'own-delivery' => [
                'name' => 'Entrega própria', 'type' => ShippingMethodType::OwnDelivery, 'delivery_days_min' => 1, 'delivery_days_max' => 2,
                'position' => 10, 'accepts_free_shipping_coupon' => true, 'weight_basis' => WeightBasis::Real,
            ],
            'table-regional' => [
                'name' => 'Transportadora regional (tabela)', 'type' => ShippingMethodType::TableRate, 'delivery_days_min' => 2,
                'delivery_days_max' => 4, 'position' => 20, 'accepts_free_shipping_coupon' => true, 'weight_basis' => WeightBasis::Chargeable,
            ],
            'table-cep' => [
                'name' => 'Frete por CEP', 'type' => ShippingMethodType::TableRate, 'delivery_days_min' => 3, 'delivery_days_max' => 6,
                'position' => 30, 'accepts_free_shipping_coupon' => true, 'weight_basis' => WeightBasis::Real,
            ],
        ];

        foreach ($methods as $code => $attributes) {
            $this->methodIds[$code] = ShippingMethod::query()->updateOrCreate(['code' => $code], [...$attributes, 'is_active' => true])->id;
        }
    }

    private function seedZones(): void
    {
        $cities = [
            'Blumenau' => '4202404', 'Gaspar' => '4205902', 'Indaial' => '4207502', 'Pomerode' => '4213203', 'Joinville' => '4209102',
        ];
        foreach ($cities as $name => $code) {
            $zone = $this->zone($name);
            $zone->cities()->updateOrCreate(['city_ibge_code' => $code], ['city_name' => $name, 'state' => 'SC']);
        }

        foreach (['CEP Blumenau (faixa)' => ['89000000', '89099999'], 'CEP Grande Florianópolis' => ['88000000', '88139999']] as $name => [$start, $end]) {
            $this->zone($name)->postalRanges()->updateOrCreate(['start_postal_code' => $start, 'end_postal_code' => $end]);
        }

        $this->zone('Santa Catarina')->states()->updateOrCreate(['state' => 'SC']);
    }

    private function zone(string $name): ShippingZone
    {
        $zone = ShippingZone::query()->updateOrCreate(['name' => $name], ['is_active' => true]);
        $this->zoneIds[$name] = $zone->id;

        return $zone;
    }

    private function seedRules(): void
    {
        $fixed = ShippingPriceType::Fixed;
        $perKg = ShippingPriceType::FixedPlusPerKg;
        $free = ShippingPriceType::Free;

        // [method, zone|null, name, priority, conditions, price type, price, per kg, days min, days max, extra]
        $rules = [
            ['own-delivery', null, 'Grátis acima de R$ 500', 10, ['min_subtotal_cents' => 50000, 'max_weight_grams' => 100000], $free, 0, 0, null, null],
            ['own-delivery', 'Blumenau', 'Entrega Blumenau', 100, ['max_weight_grams' => 100000], $fixed, 2000, 0, 1, 1],
            ['own-delivery', 'Gaspar', 'Entrega Gaspar', 100, ['max_weight_grams' => 100000], $fixed, 3000, 0, 1, 2],
            ['own-delivery', 'Indaial', 'Entrega Indaial', 100, ['max_weight_grams' => 100000], $fixed, 3500, 0, 2, 2],
            ['own-delivery', 'Pomerode', 'Entrega Pomerode', 100, ['max_weight_grams' => 100000, 'max_package_length_cm' => '200.0'], $fixed, 3500, 0, 2, 2],
            ['table-regional', 'Blumenau', 'Blumenau até 5 kg', 10, ['max_weight_grams' => 5000], $fixed, 1500, 0, null, null],
            ['table-regional', 'Blumenau', 'Blumenau até 10 kg', 20, ['min_weight_grams' => 5001, 'max_weight_grams' => 10000], $fixed, 2000, 0, null, null],
            ['table-regional', 'Blumenau', 'Blumenau até 20 kg', 30, ['min_weight_grams' => 10001, 'max_weight_grams' => 20000], $fixed, 2800, 0, null, null],
            ['table-regional', 'Joinville', 'Joinville até 30 kg', 50, ['max_weight_grams' => 30000], $perKg, 2500, 150, 3, 4],
            ['table-regional', 'Santa Catarina', 'SC até 30 kg', 200, ['max_weight_grams' => 30000, 'min_price_cents' => 3500], $perKg, 3000, 200, 3, 6],
            ['table-cep', 'CEP Blumenau (faixa)', 'CEP 89000000–89099999', 100, ['max_weight_grams' => 30000], $fixed, 1800, 0, null, null],
            ['table-cep', 'CEP Grande Florianópolis', 'CEP 88000000–88139999', 100, ['max_weight_grams' => 30000], $fixed, 4500, 0, null, null],
            ['table-cep', null, 'Grátis acima de R$ 500', 10, ['min_subtotal_cents' => 50000, 'max_weight_grams' => 30000], $free, 0, 0, null, null],
        ];

        foreach ($rules as [$method, $zone, $name, $priority, $conditions, $type, $price, $perKgCents, $daysMin, $daysMax]) {
            ShippingRule::query()->updateOrCreate(
                ['method_id' => $this->methodIds[$method], 'name' => $name],
                [
                    'zone_id' => $zone === null ? null : $this->zoneIds[$zone],
                    'priority' => $priority,
                    'min_weight_grams' => null, 'max_weight_grams' => null, 'min_subtotal_cents' => null, 'max_subtotal_cents' => null,
                    'min_volume_cm3' => null, 'max_volume_cm3' => null, 'max_package_length_cm' => null,
                    'min_price_cents' => null, 'max_price_cents' => null,
                    ...$conditions,
                    'price_type' => $type,
                    'price_cents' => $price,
                    'per_kg_cents' => $perKgCents,
                    'percentage_bp' => 0,
                    'delivery_days_min' => $daysMin,
                    'delivery_days_max' => $daysMax,
                    'is_active' => true,
                ],
            );
        }
    }
}
