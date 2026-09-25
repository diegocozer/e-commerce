<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Enums\WebhookEventStatus;
use App\Shared\Casts\JsonObjectCast;
use Database\Factories\Payments\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Received webhook (dedupe + trail, DATABASE.md §3.7.3). Dedupe by
 * (provider, external_id) only for signature_valid = true (DB-12).
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property string $external_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property bool $signature_valid
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property int|null $payment_id
 */
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    protected $table = 'webhook_events';

    protected $fillable = ['provider', 'external_id', 'event_type', 'payload', 'signature_valid', 'received_ip'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'payload' => JsonObjectCast::class,
            'signature_valid' => 'boolean',
            'status' => WebhookEventStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    protected static function newFactory(): WebhookEventFactory
    {
        return WebhookEventFactory::new();
    }
}
