<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Orders\Contracts\OrderPlacement;
use App\Modules\Orders\DTOs\OrderData;
use App\Modules\Orders\DTOs\OrderLineData;
use App\Modules\Orders\DTOs\PlaceOrderData;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderPlaced;
use App\Modules\Orders\Exceptions\TooManyPendingOrders;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Pricing\Exceptions\CouponInvalid;
use App\Shared\Domain\ActorType;
use App\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * OrderPlacement implementation (ARCHITECTURE.md §4.3). Must run inside the
 * checkout transaction; it never opens its own transaction and never calls HTTP.
 */
final class OrderPlacementService implements OrderPlacement
{
    public const int MAX_PENDING_ORDERS = 3;

    public function __construct(
        private readonly OrderNumberGenerator $numbers,
        private readonly InventoryService $inventory,
        private readonly CouponService $coupons,
        private readonly OrderStockReservations $reservations,
    ) {}

    public function place(PlaceOrderData $data): OrderData
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OrderPlacement::place() must run inside the checkout transaction.');
        }

        $this->assertConsistent($data);

        $pending = $this->pendingOrders($data->customerId);
        if (count($pending) >= self::MAX_PENDING_ORDERS) {
            throw TooManyPendingOrders::with($pending);
        }

        $order = $this->insertOrder($data);
        $this->insertItems($order, $data);

        $history = new OrderStatusHistory(['to_status' => OrderStatus::PendingPayment, 'actor_type' => ActorType::Customer, 'actor_id' => $data->customerId]);
        $history->order_id = $order->id;
        $history->save();

        $this->inventory->reserve($this->reservations->forOrder($order->id));

        if ($data->coupon !== null && $data->coupon->valid && $data->coupon->code !== null) {
            $this->redeemCoupon($data, $order);
        }

        OrderPlaced::dispatch($order->id, $order->uuid, $order->number, $order->customer_id, $order->total_cents, $order->payment_method->value);

        return OrderData::fromModel($order->refresh());
    }

    public function findByIdempotencyKey(int $customerId, string $key): ?OrderData
    {
        $order = Order::query()->where('customer_id', $customerId)->where('idempotency_key', $key)->first();

        return $order === null ? null : OrderData::fromModel($order);
    }

    public function pendingOrders(int $customerId): array
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->where('status', OrderStatus::PendingPayment->value)
            ->orderBy('placed_at')
            ->get(['uuid', 'number'])
            ->map(static fn (Order $o): array => ['uuid' => $o->uuid, 'number' => $o->number])
            ->values()
            ->all();
    }

    public function find(int $orderId): ?OrderData
    {
        $order = Order::query()->find($orderId);

        return $order === null ? null : OrderData::fromModel($order);
    }

    private function assertConsistent(PlaceOrderData $data): void
    {
        if ($data->lines === []) {
            throw new InvalidArgumentException('An order needs at least one line.');
        }

        $subtotal = Money::sum(array_map(static fn (OrderLineData $l): Money => $l->subtotal, $data->lines));
        if (! $subtotal->equals($data->subtotal)) {
            throw new InvalidArgumentException('Order subtotal differs from the sum of the lines.');
        }

        $shippingDiscount = $data->shippingDiscount();
        if ($shippingDiscount->isNegative() || $shippingDiscount->greaterThan($data->shippingTotal)
            || $data->discount->isNegative() || $data->discount->greaterThan($data->subtotal) || $data->total->isNegative()) {
            throw new InvalidArgumentException('Inconsistent order totals.');
        }

        if ($data->coupon !== null && $data->coupon->valid && $data->couponContext === null) {
            throw new InvalidArgumentException('couponContext is required to redeem the coupon.');
        }
    }

    private function insertOrder(PlaceOrderData $data): Order
    {
        $address = $data->shippingAddress;
        $pickup = $data->shipping->isPickup();
        $coupon = $data->coupon !== null && $data->coupon->valid ? $data->coupon : null;

        $order = new Order;
        $order->forceFill([
            'number' => $this->numbers->next(),
            'customer_id' => $data->customerId,
            'idempotency_key' => $data->idempotencyKey,
            'checkout_fingerprint' => $data->fingerprint,
            'status' => OrderStatus::PendingPayment,
            'payment_status' => OrderPaymentStatus::Pending,
            'payment_method' => $data->paymentMethod,
            'subtotal_cents' => $data->subtotal->cents(),
            'discount_cents' => $data->discount->cents(),
            'shipping_cents' => $data->shippingTotal->cents(),
            'shipping_discount_cents' => $data->shippingDiscount()->cents(),
            'total_cents' => $data->total->cents(),
            'coupon_id' => $coupon?->couponId,
            'coupon_code' => $coupon?->couponId !== null ? $coupon->code : null,
            'customer_type' => $data->customer->type,
            'customer_name' => $data->customer->name,
            'customer_email' => $data->customer->email,
            'customer_document' => $data->customer->document,
            'customer_phone' => $data->customer->phone,
            'customer_company_name' => $data->customer->companyName,
            'customer_state_registration' => $data->customer->stateRegistration,
            'customer_address_id' => $address->customerAddressId,
            'shipping_recipient_name' => $pickup ? null : $address->recipientName,
            'shipping_phone' => $pickup ? null : $address->phone,
            'shipping_postal_code' => $pickup ? null : $address->postalCode,
            'shipping_street' => $pickup ? null : $address->street,
            'shipping_number' => $pickup ? null : $address->number,
            'shipping_complement' => $pickup ? null : $address->complement,
            'shipping_district' => $pickup ? null : $address->district,
            'shipping_city' => $pickup ? null : $address->city,
            'shipping_state' => $pickup ? null : $address->state,
            'shipping_city_ibge_code' => $pickup ? null : $address->cityIbgeCode,
            'shipping_reference' => $pickup ? null : $address->reference,
            'shipping_method_id' => $data->shipping->methodId,
            'shipping_rule_id' => $data->shipping->ruleId,
            'shipping_option_id' => $data->shipping->optionId,
            'shipping_method_name' => $data->shipping->methodName,
            'shipping_method_type' => $data->shipping->methodType,
            'shipping_carrier_code' => $data->shipping->carrierCode,
            'shipping_service_code' => $data->shipping->serviceCode,
            'shipping_delivery_days_min' => $data->shipping->deliveryDaysMin,
            'shipping_delivery_days_max' => $data->shipping->deliveryDaysMax,
            'shipping_quote_uuid' => $data->shipping->quoteUuid,
            'total_weight_grams' => $data->shipping->totalWeightGrams,
            'total_volume_cm3' => $data->shipping->totalVolumeCm3,
            'notes' => $data->notes,
            'placed_ip' => $data->placedIp,
            'placed_at' => now(),
            'expires_at' => $data->expiresAt,
        ]);
        $order->save();

        return $order;
    }

    private function insertItems(Order $order, PlaceOrderData $data): void
    {
        $discounts = $this->lineDiscounts($data);

        foreach ($data->lines as $index => $line) {
            $discount = $discounts[$index];
            $item = new OrderItem([
                'variant_id' => $line->variantId,
                'product_id' => $line->productId,
                'product_name' => $line->productName,
                'variant_name' => $line->variantName,
                'sku' => $line->sku,
                'sale_unit' => $line->saleUnit,
                'quantity' => $line->quantity,
                'width_mm' => $line->widthMm,
                'height_mm' => $line->heightMm,
                'pieces' => $line->pieces,
                'billable_quantity' => $line->billableQuantity,
                'stock_quantity' => $line->stockQuantity,
                'price_source' => $line->priceSource,
                'price_list_id' => $line->priceListId,
                'promotion_id' => $line->promotionId,
                'weight_grams' => $line->weightGrams,
            ]);
            $item->forceFill([
                'order_id' => $order->id,
                'base_unit_price_cents' => $line->baseUnitPrice->cents(),
                'unit_price_cents' => $line->unitPrice->cents(),
                'subtotal_cents' => $line->subtotal->cents(),
                'discount_cents' => $discount->cents(),
                'total_cents' => $line->subtotal->subtract($discount)->cents(),
            ]);
            $item->save();
        }
    }

    /** @return list<Money> coupon apportionment per line (proportional, remainder on the last line) */
    private function lineDiscounts(PlaceOrderData $data): array
    {
        $given = array_map(static fn (OrderLineData $l): ?Money => $l->discount, $data->lines);
        if (array_filter($given, static fn (?Money $m): bool => $m !== null) !== []) {
            $resolved = array_map(static fn (?Money $m): Money => $m ?? Money::zero(), $given);
            if (! Money::sum($resolved)->equals($data->discount)) {
                throw new InvalidArgumentException('Line discounts must add up to the order discount.');
            }

            return $resolved;
        }

        if ($data->discount->isZero() || $data->subtotal->isZero()) {
            return array_map(static fn (): Money => Money::zero(), $data->lines);
        }

        return $data->discount->allocate(array_map(static fn (OrderLineData $l): int => $l->subtotal->cents(), $data->lines));
    }

    private function redeemCoupon(PlaceOrderData $data, Order $order): void
    {
        /** @var CouponEvaluation $expected */
        $expected = $data->coupon;
        $redeemed = $this->coupons->redeem((string) $expected->code, $data->couponContext, $order->id);

        if (! $redeemed->valid) {
            throw CouponInvalid::from($redeemed, (string) $expected->code);
        }
        if (! $redeemed->discount->equals($expected->discount) || $redeemed->freeShipping !== $expected->freeShipping) {
            throw CouponInvalid::from($redeemed, (string) $expected->code);
        }

        // The redemption (under the coupon lock) is authoritative for the snapshot.
        if ($redeemed->couponId !== null) {
            $order->forceFill(['coupon_id' => $redeemed->couponId, 'coupon_code' => $redeemed->code ?? $expected->code])->save();
        }
    }
}
