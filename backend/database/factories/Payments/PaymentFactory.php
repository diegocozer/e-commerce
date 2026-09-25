<?php

declare(strict_types=1);

namespace Database\Factories\Payments;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => PaymentProvider::Sandbox,
            'method' => PaymentMethod::Pix,
            'status' => PaymentStatus::Pending,
            'amount_cents' => fn (array $attributes): int => Order::query()->findOrFail($attributes['order_id'])->total_cents,
            'refunded_cents' => 0,
            'external_id' => 'sbx_'.fake()->unique()->uuid(),
            'pix_copy_paste' => '00020126580014br.gov.bcb.pix0136'.fake()->uuid(),
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => PaymentStatus::Approved, 'paid_at' => now()]);
    }
}
