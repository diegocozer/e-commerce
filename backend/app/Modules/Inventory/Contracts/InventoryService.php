<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\DTOs\StockReservation;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockAdjustment;
use App\Modules\Inventory\Exceptions\StaleStock;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Quantity;

/**
 * Stock balance and movements (ADR-008, ARCHITECTURE.md §2.4 Inventory,
 * IMPLEMENTATION_PLAN.md §5.3). Every write runs inside a transaction with
 * SELECT ... FOR UPDATE on `inventory` ordered by variant_id and records an
 * immutable `inventory_movements` row.
 */
interface InventoryService
{
    /**
     * Available = on_hand − reserved, without locks. Variants without an
     * inventory row are reported as zero.
     *
     * @param  list<int>  $variantIds
     * @return array<int, Quantity>
     */
    public function availability(array $variantIds): array;

    /**
     * Requires an open transaction; SELECT ... FOR UPDATE ORDER BY variant_id.
     *
     * @param  list<int>  $variantIds
     */
    public function lockForUpdate(array $variantIds): void;

    /**
     * reserved += q per variant (lines of the same variant are summed).
     *
     * @throws InsufficientStock (409 insufficient_stock, with items)
     */
    public function reserve(StockReservation $r): void;

    /** Payment approved: on_hand −= q, reserved −= q (movement `out`; idempotent per reference). */
    public function commit(StockReservation $r): void;

    /** Unpaid order cancelled/expired: reserved −= q (movement `release`; idempotent per reference). */
    public function release(StockReservation $r): void;

    /** Paid order cancelled before shipping: on_hand += q (movement `return`; idempotent per reference). */
    public function restock(StockReservation $r): void;

    /** Goods receipt: on_hand += q (movement `in`). */
    public function receive(int $variantId, Quantity $q, string $reason, ActorRef $actor): void;

    /**
     * Manual adjustment: on_hand = $newOnHand (movement `adjust`).
     *
     * @throws InvalidStockAdjustment when new < reserved or unchanged
     * @throws StaleStock when $expectedOnHand differs from the current value
     */
    public function adjust(int $variantId, Quantity $newOnHand, string $reason, ActorRef $actor, ?Quantity $expectedOnHand = null): void;
}
