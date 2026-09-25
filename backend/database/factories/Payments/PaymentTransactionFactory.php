<?php

declare(strict_types=1);

namespace Database\Factories\Payments;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentTransaction> */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'type' => PaymentTransactionType::Create,
            'status_before' => null,
            'status_after' => PaymentStatus::Pending,
            'amount_cents' => 1000,
            'external_id' => null,
            'payload' => ['status' => 'pending'],
        ];
    }
}
