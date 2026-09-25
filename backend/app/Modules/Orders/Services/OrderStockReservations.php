<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockReservation;
use App\Modules\Orders\Models\OrderItem;
use App\Shared\Domain\Quantity;

/** Builds the stock reservation of an order from its item snapshots (stock_quantity, summed by variant). */
final class OrderStockReservations
{
    public const string REFERENCE_TYPE = 'order';

    public function forOrder(int $orderId): StockReservation
    {
        /** @var array<int, Quantity> $byVariant */
        $byVariant = [];
        foreach (OrderItem::query()->where('order_id', $orderId)->orderBy('id')->get(['variant_id', 'stock_quantity']) as $item) {
            $byVariant[$item->variant_id] = ($byVariant[$item->variant_id] ?? Quantity::zero())->add($item->stock_quantity);
        }
        ksort($byVariant);

        $lines = [];
        foreach ($byVariant as $variantId => $quantity) {
            $lines[] = new StockLine($variantId, $quantity);
        }

        return new StockReservation(self::REFERENCE_TYPE, $orderId, $lines);
    }
}
