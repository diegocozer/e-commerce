<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Database\Factories\Shipping\ShippingQuoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Persisted shipping quote (TTL 30 min, DATABASE.md §3.8.5). Route key `uuid`.
 * `cart_id`/`customer_id` reference Cart/Customers, which Shipping does not
 * depend on (Cart::shippingQuotes() is the read side).
 *
 * @property int $id
 * @property string $uuid
 * @property int $cart_id
 * @property int|null $customer_id
 * @property string $postal_code
 * @property string $request_hash
 * @property int $subtotal_cents
 * @property int $total_weight_grams
 * @property int $total_volume_cm3
 * @property bool $coupon_free_shipping
 * @property list<array<string, mixed>> $options
 * @property list<array<string, mixed>> $unavailable
 */
class ShippingQuote extends Model
{
    /** @use HasFactory<ShippingQuoteFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'shipping_quotes';

    protected $fillable = [
        'cart_id', 'customer_id', 'postal_code', 'city_ibge_code', 'state', 'request_hash', 'subtotal_cents',
        'total_weight_grams', 'total_volume_cm3', 'coupon_free_shipping', 'options', 'unavailable', 'expires_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'subtotal_cents' => 'integer',
            'total_weight_grams' => 'integer',
            'total_volume_cm3' => 'integer',
            'coupon_free_shipping' => 'boolean',
            'options' => 'array',
            'unavailable' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->expires_at <= ($at ?? now());
    }

    protected static function newFactory(): ShippingQuoteFactory
    {
        return ShippingQuoteFactory::new();
    }
}
