<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Exceptions\CartItemNotFound;
use App\Modules\Cart\Exceptions\CartNotFound;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartItemGuard;
use App\Modules\Cart\Services\CartLocator;
use App\Modules\Cart\Services\LastSeenPrices;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /cart/items/{id}. The item is always looked up through the cart
 * (SECURITY §5). m² with the same dimensions as another line → lines merged.
 */
final class UpdateCartItem
{
    public function __construct(
        private readonly CartLocator $carts,
        private readonly CartItemGuard $guard,
        private readonly LastSeenPrices $lastSeen,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(?int $customerId, ?string $token, int $itemId, array $input): Cart
    {
        return DB::transaction(function () use ($customerId, $token, $itemId, $input): Cart {
            $cart = $this->carts->find($customerId, $token, lock: true) ?? throw new CartNotFound;
            /** @var CartItem|null $item */
            $item = $cart->items()->whereKey($itemId)->lockForUpdate()->first();
            if ($item === null) {
                throw new CartItemNotFound;
            }
            $variant = $this->guard->activeVariant((int) $item->variant_id);
            $this->guard->fill($item, $variant, $input, partial: true);

            /** @var list<CartItem> $others */
            $others = $cart->items()->where('variant_id', $item->variant_id)->whereKeyNot($item->id)->lockForUpdate()->get()->all();
            $twin = null;
            foreach ($others as $other) {
                if ($other->width_mm === $item->width_mm && $other->height_mm === $item->height_mm) {
                    $twin = $other;
                }
            }

            if ($twin !== null) {
                $twin->pieces = (int) $twin->pieces + (int) $item->pieces;
                if ($item->quantity !== null) {
                    $twin->quantity = $twin->quantity->add($item->quantity);
                }
                $this->guard->resolve($variant, $twin);
                $this->guard->assertStock($variant, $others);
                $item->delete();
                $twin->save();
                $changed = (int) $twin->id;
            } else {
                $this->guard->resolve($variant, $item);
                $this->guard->assertStock($variant, [...$others, $item]);
                $item->save();
                $changed = (int) $item->id;
            }

            $this->carts->touch($cart);
            $this->lastSeen->remember($cart, [$changed], $customerId);

            return $cart;
        });
    }
}
