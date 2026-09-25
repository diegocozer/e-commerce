<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Orders\DTOs\OrderData;
use App\Modules\Orders\DTOs\PlaceOrderData;
use App\Modules\Orders\Exceptions\TooManyPendingOrders;
use App\Modules\Pricing\Exceptions\CouponInvalid;

/** Order creation used by Checkout (ARCHITECTURE.md §2.4 Orders). */
interface OrderPlacement
{
    /**
     * Called by Checkout INSIDE its transaction (after the advisory lock): writes
     * the order (number from order_number_seq) + item snapshots + status history,
     * reserves stock (InventoryService::reserve) and redeems the coupon
     * (CouponService::redeem, when `$data->coupon` is valid). Never calls HTTP.
     * Dispatches OrderPlaced (listeners run after commit).
     *
     * @throws TooManyPendingOrders (409 too_many_pending_orders) when the customer already has 3 pending orders
     * @throws InsufficientStock (409 insufficient_stock)
     * @throws CouponInvalid (409 coupon_invalid)
     */
    public function place(PlaceOrderData $data): OrderData;

    public function findByIdempotencyKey(int $customerId, string $key): ?OrderData;

    /**
     * Pending orders of the customer — for the 409 too_many_pending_orders body.
     *
     * @return list<array{uuid: string, number: string}>
     */
    public function pendingOrders(int $customerId): array;

    public function find(int $orderId): ?OrderData;
}
