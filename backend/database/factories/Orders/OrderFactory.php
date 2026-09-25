<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderNumberGenerator;
use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Default: PIX order awaiting payment, own delivery to Blumenau, totals
 * consistent with orders_total_check.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = fake()->numberBetween(1000, 100000);
        $shipping = 2000;

        return [
            'number' => OrderNumberGenerator::format('CV-', fake()->unique()->numberBetween(900000, 9999999)),
            'customer_id' => Customer::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'checkout_fingerprint' => hash('sha256', Str::random()),
            'status' => OrderStatus::PendingPayment,
            'payment_status' => OrderPaymentStatus::Pending,
            'payment_method' => PaymentMethod::Pix,
            'subtotal_cents' => $subtotal,
            'discount_cents' => 0,
            'shipping_cents' => $shipping,
            'shipping_discount_cents' => 0,
            'total_cents' => $subtotal + $shipping,
            'customer_type' => CustomerType::Individual,
            'customer_name' => fn (array $a): string => Customer::query()->findOrFail($a['customer_id'])->name,
            'customer_email' => fn (array $a): string => Customer::query()->findOrFail($a['customer_id'])->email,
            'customer_document' => fn (array $a): string => Customer::query()->findOrFail($a['customer_id'])->cpf ?? '52998224725',
            'customer_phone' => null,
            'shipping_recipient_name' => fake()->name(),
            'shipping_postal_code' => '89010001',
            'shipping_street' => 'Rua XV de Novembro',
            'shipping_number' => '100',
            'shipping_district' => 'Centro',
            'shipping_city' => 'Blumenau',
            'shipping_state' => 'SC',
            'shipping_city_ibge_code' => '4202404',
            'shipping_option_id' => '2:100',
            'shipping_method_name' => 'Entrega própria',
            'shipping_method_type' => 'own_delivery',
            'shipping_delivery_days_min' => 1,
            'shipping_delivery_days_max' => 2,
            'total_weight_grams' => 1000,
            'total_volume_cm3' => 6000,
            'placed_ip' => fake()->ipv4(),
            'placed_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function paid(): static
    {
        return $this->state([
            'status' => OrderStatus::Paid,
            'payment_status' => OrderPaymentStatus::Approved,
            'paid_at' => now(),
            'expires_at' => null,
        ]);
    }

    public function pickup(): static
    {
        return $this->state([
            'shipping_option_id' => '1:pickup',
            'shipping_method_name' => 'Retirada na empresa',
            'shipping_method_type' => 'pickup',
            'shipping_cents' => 0,
            'total_cents' => fn (array $a): int => $a['subtotal_cents'] - $a['discount_cents'],
            'shipping_recipient_name' => null,
            'shipping_postal_code' => null,
            'shipping_street' => null,
            'shipping_number' => null,
            'shipping_district' => null,
            'shipping_city' => null,
            'shipping_state' => null,
            'shipping_city_ibge_code' => null,
        ]);
    }
}
