<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\PaymentStatus;
use Database\Factories\Payments\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One charge attempt for an order (DATABASE.md §3.7.1). Payments knows the
 * order only by `order_id` (no dependency on Orders). Status and amounts are
 * set explicitly by the payment service (not fillable). Route key `uuid`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property PaymentProvider $provider
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property int $amount_cents
 * @property int $refunded_cents
 * @property string|null $external_id
 * @property string $idempotency_key
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'external_id', 'pix_copy_paste', 'pix_qr_code_base64', 'boleto_url', 'boleto_barcode', 'expires_at', 'failure_reason',
    ];

    protected $hidden = ['idempotency_key'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid', 'idempotency_key'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
            'refunded_cents' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    /** @return HasMany<PaymentRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /** @return HasMany<WebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
