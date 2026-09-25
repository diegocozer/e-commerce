<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;

/**
 * cart_items.last_seen_unit_price_cents (ADR-028): the unit price the customer
 * last saw, base of the `price_changed` warning (RN-CAR-010).
 */
final class LastSeenPrices
{
    public function __construct(private readonly CartCalculator $calculator) {}

    /** @param list<int>|null $itemIds null = every line (POST /cart/acknowledge-prices) */
    public function remember(Cart $cart, ?array $itemIds, ?int $customerId): void
    {
        $cart->unsetRelation('items');
        $snapshot = $this->calculator->snapshot($cart, $customerId);
        foreach ($snapshot->lines as $line) {
            if ($line->price === null || ($itemIds !== null && ! in_array($line->cartItemId, $itemIds, true))) {
                continue;
            }
            CartItem::query()->whereKey($line->cartItemId)->update(['last_seen_unit_price_cents' => $line->price->unitPrice->cents()]);
        }
        $cart->unsetRelation('items');
    }
}
