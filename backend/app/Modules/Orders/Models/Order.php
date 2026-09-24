<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\CouponRedemption;
use Database\Factories\Orders\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Order with full snapshot of customer, address and shipping (DATABASE.md
 * §3.6.2). Never soft deleted nor deleted. Customer routes use `uuid`
 * (route key); admin routes bind by id explicitly ({order:id}).
 *
 * Status, payment status, customer, money totals, idempotency data and
 * lifecycle timestamps are NOT fillable: they are set explicitly by the
 * Orders actions (ADR-012). `shipping_method_id`/`shipping_rule_id` reference
 * Shipping, which Orders does not depend on (snapshot columns are used instead).
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property int $customer_id
 * @property string $idempotency_key
 * @property string $checkout_fingerprint
 * @property OrderStatus $status
 * @property OrderPaymentStatus $payment_status
 * @property PaymentMethod $payment_method
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $shipping_cents
 * @property int $shipping_discount_cents
 * @property int $total_cents
 * @property CustomerType $customer_type
 * @property string $customer_document
 * @property string $shipping_method_type
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'orders';

    protected $fillable = [
        'shipping_recipient_name', 'shipping_phone', 'shipping_postal_code', 'shipping_street', 'shipping_number',
        'shipping_complement', 'shipping_district', 'shipping_city', 'shipping_state', 'shipping_city_ibge_code',
        'shipping_reference', 'notes', 'internal_notes', 'tracking_code', 'tracking_url', 'estimated_delivery_date',
        'picked_up_by_name', 'picked_up_by_document', 'cancel_reason', 'cancellation_request_reason',
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
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'customer_type' => CustomerType::class,
            'cancel_reason_code' => CancelReasonCode::class,
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'shipping_cents' => 'integer',
            'shipping_discount_cents' => 'integer',
            'total_cents' => 'integer',
            'shipping_delivery_days_min' => 'integer',
            'shipping_delivery_days_max' => 'integer',
            'total_weight_grams' => 'integer',
            'total_volume_cm3' => 'integer',
            'placed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'ready_for_pickup_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'picked_up_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancellation_requested_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'estimated_delivery_date' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CustomerAddress, $this> */
    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class)->withTrashed();
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    /** @return HasOne<CouponRedemption, $this> */
    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
