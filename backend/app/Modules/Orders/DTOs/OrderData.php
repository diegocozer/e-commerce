<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/** Read model of an order for other modules. */
final readonly class OrderData
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $number,
        public int $customerId,
        public OrderStatus $status,
        public OrderPaymentStatus $paymentStatus,
        public PaymentMethod $paymentMethod,
        public Money $subtotal,
        public Money $discount,
        public Money $shipping,
        public Money $shippingDiscount,
        public Money $total,
        public string $idempotencyKey,
        public string $checkoutFingerprint,
        public string $shippingMethodType,
        public CarbonImmutable $placedAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $cancelledAt,
    ) {}

    public static function fromModel(Order $order): self
    {
        return new self(
            id: $order->id,
            uuid: $order->uuid,
            number: $order->number,
            customerId: $order->customer_id,
            status: $order->status,
            paymentStatus: $order->payment_status,
            paymentMethod: $order->payment_method,
            subtotal: Money::ofCents($order->subtotal_cents),
            discount: Money::ofCents($order->discount_cents),
            shipping: Money::ofCents($order->shipping_cents),
            shippingDiscount: Money::ofCents($order->shipping_discount_cents),
            total: Money::ofCents($order->total_cents),
            idempotencyKey: $order->idempotency_key,
            checkoutFingerprint: $order->checkout_fingerprint,
            shippingMethodType: $order->shipping_method_type,
            placedAt: $order->placed_at,
            expiresAt: $order->expires_at,
            paidAt: $order->paid_at,
            cancelledAt: $order->cancelled_at,
        );
    }
}
