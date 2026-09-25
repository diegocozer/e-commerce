<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartItemGuard;
use App\Modules\Cart\Services\CartLocator;
use App\Modules\Cart\Services\LastSeenPrices;
use Illuminate\Support\Facades\DB;

/**
 * POST /cart/items (API.md §3.B, ARCHITECTURE §4.1). Identical line (variant +
 * width + height) → sums quantity/pieces and revalidates (DB-10, RN-CAR-004).
 */
final class AddCartItem
{
    public function __construct(
        private readonly CartLocator $carts,
        private readonly CartItemGuard $guard,
        private readonly LastSeenPrices $lastSeen,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated body
     * @return array{cart: Cart, cart_created: bool, line_created: bool}
     */
    public function execute(?int $customerId, ?string $token, array $input): array
    {
        return DB::transaction(function () use ($customerId, $token, $input): array {
            $variant = $this->guard->activeVariant((int) $input['variant_id']);
            $candidate = new CartItem;
            $candidate->variant_id = $variant->id;
            $this->guard->fill($candidate, $variant, $input);
            $this->guard->resolve($variant, $candidate); // the requested configuration alone

            [$cart, $cartCreated] = $this->carts->findOrCreate($customerId, $token);
            /** @var list<CartItem> $variantItems */
            $variantItems = $cart->items()->where('variant_id', $variant->id)->lockForUpdate()->get()->all();

            $existing = null;
            foreach ($variantItems as $item) {
                if ($item->width_mm === $candidate->width_mm && $item->height_mm === $candidate->height_mm) {
                    $existing = $item;
                }
            }

            if ($existing !== null) {
                if ($candidate->quantity !== null) {
                    $existing->quantity = $existing->quantity->add($candidate->quantity);
                } else {
                    $existing->pieces = (int) $existing->pieces + (int) $candidate->pieces;
                }
                $this->guard->resolve($variant, $existing); // the sum (max, step)
                $this->guard->assertStock($variant, $variantItems);
                $existing->save();
                $line = $existing;
            } else {
                $this->guard->assertLineLimit($cart);
                $this->guard->assertStock($variant, [...$variantItems, $candidate]);
                $candidate->cart_id = $cart->id;
                $candidate->save();
                $line = $candidate;
            }

            $this->carts->touch($cart);
            $this->lastSeen->remember($cart, [(int) $line->id], $customerId);

            return ['cart' => $cart, 'cart_created' => $cartCreated, 'line_created' => $existing === null];
        });
    }
}
