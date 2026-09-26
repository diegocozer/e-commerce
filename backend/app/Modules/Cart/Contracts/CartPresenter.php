<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Shared\Domain\Quantity;

/** API shapes of cart data reused by Checkout (API.md §2.5 CartItem / CartCoupon). */
interface CartPresenter
{
    /** @return list<array<string, mixed>> CartItem[] */
    public function items(CartSnapshot $cart): array;

    /** @return array<string, mixed> full API.md Cart of a cart (recalculated) */
    public function cart(int $cartId, ?int $customerId): array;

    /** @return array{quantity: int|float|null, width_m: int|float|null, height_m: int|float|null, pieces: int|null} */
    public function configurationOf(?Quantity $quantity, ?int $widthMm, ?int $heightMm, ?int $pieces): array;

    /** @return array<string, mixed>|null CartCoupon */
    public function coupon(CartSnapshot $cart, ?CouponEvaluation $evaluation): ?array;
}
