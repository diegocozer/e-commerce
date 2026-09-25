<?php

declare(strict_types=1);

namespace Database\Factories\Shipping;

use App\Modules\Cart\Models\Cart;
use App\Modules\Shipping\Models\ShippingQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShippingQuote> */
class ShippingQuoteFactory extends Factory
{
    protected $model = ShippingQuote::class;

    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'customer_id' => null,
            'postal_code' => '89010001',
            'city_ibge_code' => '4202404',
            'state' => 'SC',
            'request_hash' => hash('sha256', fake()->uuid()),
            'subtotal_cents' => 32000,
            'total_weight_grams' => 7200,
            'total_volume_cm3' => 12000,
            'coupon_free_shipping' => false,
            'options' => [],
            'unavailable' => [],
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
