<?php

declare(strict_types=1);

namespace Database\Factories\Payments;

use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\RefundRequesterType;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentRefund> */
class PaymentRefundFactory extends Factory
{
    protected $model = PaymentRefund::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->approved(),
            'amount_cents' => 1000,
            'status' => PaymentRefundStatus::Pending,
            'reason' => 'Cancelamento solicitado pelo cliente',
            'requested_by_type' => RefundRequesterType::System,
            'admin_user_id' => null,
        ];
    }
}
