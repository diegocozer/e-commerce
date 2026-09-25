<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\CartLocator;
use App\Modules\Cart\Services\LastSeenPrices;

/** POST /cart/acknowledge-prices (RN-CAR-010): last seen price = current price for every line. */
final class AcknowledgeCartPrices
{
    public function __construct(private readonly CartLocator $carts, private readonly LastSeenPrices $lastSeen) {}

    public function execute(?int $customerId, ?string $token): ?Cart
    {
        $cart = $this->carts->find($customerId, $token);
        if ($cart !== null) {
            $this->lastSeen->remember($cart, null, $customerId);
        }

        return $cart;
    }
}
