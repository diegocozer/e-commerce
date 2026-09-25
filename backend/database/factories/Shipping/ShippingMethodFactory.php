<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Enums\WeightBasis;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Default: own delivery. @extends Factory<ShippingMethod> */
class ShippingMethodFactory extends Factory
{
    protected $model = ShippingMethod::class;

    public function definition(): array
    {
        return [
            'name' => 'Entrega '.fake()->word(),
            'code' => 'metodo-'.fake()->unique()->numerify('#####'),
            'type' => ShippingMethodType::OwnDelivery,
            'carrier_id' => null,
            'description' => null,
            'delivery_days_min' => 1,
            'delivery_days_max' => 2,
            'is_active' => true,
            'position' => 10,
            'weight_basis' => WeightBasis::Real,
            'handling_days' => 0,
            'accepts_free_shipping_coupon' => true,
        ];
    }

    public function pickup(): static
    {
        return $this->state([
            'type' => ShippingMethodType::Pickup,
            'pickup_street' => 'Rua XV de Novembro',
            'pickup_number' => '1000',
            'pickup_district' => 'Centro',
            'pickup_city' => 'Blumenau',
            'pickup_state' => 'SC',
            'pickup_postal_code' => '89010001',
            'accepts_free_shipping_coupon' => false,
            'delivery_days_min' => 1,
            'delivery_days_max' => 1,
        ]);
    }

    public function tableRate(): static
    {
        return $this->state(['type' => ShippingMethodType::TableRate, 'weight_basis' => WeightBasis::Chargeable]);
    }

    public function carrier(): static
    {
        return $this->state([
            'type' => ShippingMethodType::Carrier,
            'carrier_id' => ShippingCarrier::factory(),
            'carrier_service_code' => 'SEDEX',
            'accepts_free_shipping_coupon' => false,
        ]);
    }
}
