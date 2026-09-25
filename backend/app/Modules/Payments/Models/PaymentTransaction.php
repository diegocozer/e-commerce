<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentTransactionType;
use App\Shared\Casts\JsonObjectCast;
use Database\Factories\Payments\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable log of each interaction/transition with the gateway
 * (DATABASE.md §3.7.2). `payload` must be sanitized before saving.
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentTransactionType $type
 * @property PaymentStatus|null $status_before
 * @property PaymentStatus $status_after
 * @property int $amount_cents
 */
class PaymentTransaction extends Model
{
    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'payment_id', 'type', 'status_before', 'status_after', 'amount_cents', 'external_id', 'webhook_event_id',
        'admin_user_id', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentTransactionType::class,
            'status_before' => PaymentStatus::class,
            'status_after' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'payload' => JsonObjectCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<WebhookEvent, $this> */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    protected static function newFactory(): PaymentTransactionFactory
    {
        return PaymentTransactionFactory::new();
    }
}
