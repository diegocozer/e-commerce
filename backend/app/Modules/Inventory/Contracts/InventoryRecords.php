<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Quantity;

/**
 * Inventory row lifecycle used by Catalog when variants are created/edited
 * (the 1:1 `inventory` row and the low-stock threshold). Balances are only
 * changed through InventoryService.
 */
interface InventoryRecords
{
    /** Creates the zeroed inventory row when missing (idempotent). */
    public function ensureRecord(int $variantId, ?Quantity $lowStockThreshold = null): void;

    /** null = use the store default (settings.inventory.default_low_stock_threshold). */
    public function setLowStockThreshold(int $variantId, ?Quantity $threshold, ActorRef $actor): void;

    /** Effective default threshold from settings. */
    public function defaultLowStockThreshold(): Quantity;
}
