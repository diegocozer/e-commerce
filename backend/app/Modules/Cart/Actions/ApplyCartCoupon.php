<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\Exceptions\CartNotFound;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Services\CartCalculator;
use App\Modules\Cart\Services\CartLocator;
use App\Modules\Pricing\Contracts\CouponService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PUT /cart/coupon and DELETE /cart/coupon (API.md §3.B, RN-CUP-006/012).
 * Invalid coupon → 422 errors.code (not stored), except `login_required` for a
 * guest, which is stored as invalid and revalidated on login.
 */
final class ApplyCartCoupon
{
    public function __construct(
        private readonly CartLocator $carts,
        private readonly CartCalculator $calculator,
        private readonly CartService $cartService,
        private readonly CouponService $coupons,
    ) {}

    /** @return array{cart: Cart, cart_created: bool} */
    public function apply(?int $customerId, ?string $token, string $code): array
    {
        $code = mb_strtoupper(trim($code));

        return DB::transaction(function () use ($customerId, $token, $code): array {
            [$cart, $created] = $this->carts->findOrCreate($customerId, $token);
            $snapshot = $this->calculator->snapshot($cart, $customerId);
            $evaluation = $this->coupons->evaluate($code, $this->cartService->toCouponContext($snapshot, null));

            $storeAsPending = ! $evaluation->valid && $customerId === null
                && $evaluation->reasonCode === 'login_required' && $evaluation->couponId !== null;
            if (! $evaluation->valid && ! $storeAsPending) {
                throw ValidationException::withMessages(['code' => [$evaluation->message ?? 'Cupom inválido ou expirado.']]);
            }

            $cart->coupon_id = $evaluation->couponId;
            $this->carts->touch($cart);

            return ['cart' => $cart, 'cart_created' => $created];
        });
    }

    public function remove(?int $customerId, ?string $token): ?Cart
    {
        return DB::transaction(function () use ($customerId, $token): ?Cart {
            $cart = $this->carts->find($customerId, $token, lock: true);
            if ($cart === null) {
                return null;
            }
            $cart->coupon_id = null;
            $this->carts->touch($cart);

            return $cart;
        });
    }
}
