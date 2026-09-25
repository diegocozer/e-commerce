<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Pricing\Enums\CouponType;
use App\Modules\Pricing\Events\CouponRedeemed;
use App\Modules\Pricing\Exceptions\CouponInvalid;
use App\Modules\Pricing\Models\Coupon;
use App\Shared\Domain\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * RN-CUP: one coupon per order, validity window, minimum subtotal, total and
 * per-customer usage limits (checked and incremented under FOR UPDATE on the
 * coupon row), discount never above the subtotal.
 */
final class DatabaseCouponService implements CouponService
{
    public const array MESSAGES = [
        'not_found' => 'Cupom inválido ou expirado.',
        'inactive' => 'Cupom inválido ou expirado.',
        'expired' => 'Cupom inválido ou expirado.',
        'usage_limit_reached' => 'Limite de uso deste cupom atingido.',
        'customer_limit_reached' => 'Você já utilizou este cupom o número máximo de vezes.',
        'login_required' => 'Entre na sua conta para usar este cupom.',
    ];

    public static function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function evaluate(string $code, CouponContext $ctx): CouponEvaluation
    {
        $coupon = Coupon::query()->where('code', self::normalize($code))->first();

        return $this->check($coupon, $code, $ctx);
    }

    public function redeem(string $code, CouponContext $ctx, int $orderId): CouponEvaluation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('CouponService::redeem() must run inside the checkout transaction.');
        }
        if ($ctx->customerId === null) {
            throw CouponInvalid::from($this->invalid('login_required', $code), $code);
        }

        $coupon = Coupon::query()->where('code', self::normalize($code))->lockForUpdate()->first();
        $evaluation = $this->check($coupon, $code, $ctx);
        if (! $evaluation->valid || $coupon === null) {
            throw CouponInvalid::from($evaluation, $code);
        }

        DB::table('coupon_redemptions')->insert([
            'coupon_id' => $coupon->id,
            'customer_id' => $ctx->customerId,
            'order_id' => $orderId,
            'discount_cents' => $evaluation->discount->cents(),
            'created_at' => now(),
        ]);
        DB::table('coupons')->where('id', $coupon->id)->update(['times_used' => DB::raw('times_used + 1'), 'updated_at' => now()]);

        CouponRedeemed::dispatch($coupon->id, $orderId, $ctx->customerId, $evaluation->discount->cents());

        return $evaluation;
    }

    public function releaseForOrder(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $redemption = DB::table('coupon_redemptions')->where('order_id', $orderId)->first();
            if ($redemption === null || $redemption->cancelled_at !== null) {
                return;
            }
            // Lock order (DATABASE.md §4.2): coupons before the redemption row update.
            DB::table('coupons')->where('id', $redemption->coupon_id)->lockForUpdate()->first();
            $updated = DB::table('coupon_redemptions')
                ->where('id', $redemption->id)
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);
            if ($updated === 1) {
                DB::table('coupons')->where('id', $redemption->coupon_id)->where('times_used', '>', 0)
                    ->update(['times_used' => DB::raw('times_used - 1'), 'updated_at' => now()]);
            }
        });
    }

    private function check(?Coupon $coupon, string $code, CouponContext $ctx): CouponEvaluation
    {
        if ($coupon === null) {
            return $this->invalid('not_found', $code);
        }

        $now = now();
        if (! $coupon->is_active || ($coupon->starts_at !== null && $coupon->starts_at->greaterThan($now))) {
            return $this->invalid('inactive', $code, $coupon);
        }
        if ($coupon->ends_at !== null && $coupon->ends_at->lessThanOrEqualTo($now)) {
            return $this->invalid('expired', $code, $coupon);
        }
        if ($ctx->subtotal->cents() < $coupon->min_order_cents) {
            return $this->invalid('min_order_not_met', $code, $coupon,
                'Pedido mínimo para este cupom: '.Money::ofCents($coupon->min_order_cents)->format().'.');
        }
        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            return $this->invalid('usage_limit_reached', $code, $coupon);
        }
        if ($coupon->usage_limit_per_customer !== null) {
            if ($ctx->customerId === null) {
                return $this->invalid('login_required', $code, $coupon);
            }
            $used = DB::table('coupon_redemptions')
                ->where('coupon_id', $coupon->id)
                ->where('customer_id', $ctx->customerId)
                ->whereNull('cancelled_at')
                ->count();
            if ($used >= $coupon->usage_limit_per_customer) {
                return $this->invalid('customer_limit_reached', $code, $coupon);
            }
        }

        $subtotal = Money::max($ctx->subtotal, Money::zero());
        $discount = match ($coupon->type) {
            CouponType::Percent => $subtotal->percentage($coupon->value),
            CouponType::Fixed => Money::ofCents($coupon->value),
            CouponType::FreeShipping => Money::zero(),
        };
        if ($coupon->type === CouponType::Percent && $coupon->max_discount_cents !== null) {
            $discount = Money::min($discount, Money::ofCents($coupon->max_discount_cents));
        }
        $discount = Money::min($discount, $subtotal); // RN-CUP-009: never below zero

        return new CouponEvaluation(
            valid: true,
            reasonCode: null,
            message: null,
            discount: $discount,
            freeShipping: $coupon->type === CouponType::FreeShipping,
            couponId: $coupon->id,
            code: $coupon->code,
            type: $coupon->type,
            description: $coupon->description,
        );
    }

    private function invalid(string $reason, string $code, ?Coupon $coupon = null, ?string $message = null): CouponEvaluation
    {
        return new CouponEvaluation(
            valid: false,
            reasonCode: $reason,
            message: $message ?? self::MESSAGES[$reason],
            discount: Money::zero(),
            freeShipping: false,
            couponId: $coupon?->id,
            code: $coupon->code ?? self::normalize($code),
            type: $coupon?->type,
            description: $coupon?->description,
        );
    }
}
