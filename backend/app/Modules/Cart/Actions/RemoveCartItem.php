<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Exceptions\CartItemNotFound;
use App\Modules\Cart\Exceptions\CartNotFound;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\CartLocator;
use Illuminate\Support\Facades\DB;

/** DELETE /cart/items/{id} and DELETE /cart. */
final class RemoveCartItem
{
    public function __construct(private readonly CartLocator $carts) {}

    public function execute(?int $customerId, ?string $token, int $itemId): Cart
    {
        return DB::transaction(function () use ($customerId, $token, $itemId): Cart {
            $cart = $this->carts->find($customerId, $token, lock: true) ?? throw new CartNotFound;
            if ($cart->items()->whereKey($itemId)->delete() === 0) {
                throw new CartItemNotFound;
            }
            $this->carts->touch($cart);

            return $cart;
        });
    }

    /** Removes every item and the coupon; keeps the token. Null when there is no cart. */
    public function clear(?int $customerId, ?string $token): ?Cart
    {
        return DB::transaction(function () use ($customerId, $token): ?Cart {
            $cart = $this->carts->find($customerId, $token, lock: true);
            if ($cart === null) {
                return null;
            }
            $cart->items()->delete();
            $cart->coupon_id = null;
            $this->carts->touch($cart);

            return $cart;
        });
    }
}
