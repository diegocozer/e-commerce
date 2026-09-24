<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Enums\RefundRequesterType;
use Database\Factories\Payments\PaymentRefundFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund request (DATABASE.md §3.7.2a). At most one active (pending/processing)
 * per payment. Status is changed explicitly by the payment service.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $amount_cents
 * @property PaymentRefundStatus $status
 * @property string $reason
 * @property string $idempotency_key
 * @property RefundRequesterType $requested_by_type
 * @property int|null $admin_user_id
 */
class PaymentRefund extends Model
{
    /** @use HasFactory<PaymentRefundFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_refunds';

    protected $fillable = ['payment_id', 'amount_cents', 'reason', 'requested_by_type', 'admin_user_id', 'external_id', 'failure_reason'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['idempotency_key'];
    }

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'status' => PaymentRefundStatus::class,
            'requested_by_type' => RefundRequesterType::class,
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected static function newFactory(): PaymentRefundFactory
    {
        return PaymentRefundFactory::new();
    }
}
