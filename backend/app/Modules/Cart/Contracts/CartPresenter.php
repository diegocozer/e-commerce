<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Pricing\DTOs\CouponEvaluation;

/** API shapes of cart data reused by Checkout (API.md §2.5 CartItem / CartCoupon). */
interface CartPresenter
{
    /** @return list<array<string, mixed>> CartItem[] */
    public function items(CartSnapshot $cart): array;

    /** @return array<string, mixed>|null CartCoupon */
    public function coupon(CartSnapshot $cart, ?CouponEvaluation $evaluation): ?array;
}
