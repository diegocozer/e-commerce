<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\DTOs\CouponContext;
use App\Modules\Pricing\Enums\CouponType;
use App\Modules\Pricing\Exceptions\CouponInvalid;
use App\Modules\Pricing\Models\Coupon;
use App\Shared\Domain\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** RN-CUP examples (subtotal R$ 200,00). */
final class CouponServiceTest extends TestCase
{
    use RefreshDatabase;

    private function ctx(int $subtotal, ?int $customerId = null): CouponContext
    {
        return new CouponContext($customerId, Money::ofCents($subtotal), [], Money::ofCents(2500));
    }

    /** @param  array<string, mixed>  $attrs */
    private function coupon(array $attrs): Coupon
    {
        return Coupon::query()->create([
            'code' => 'C'.fake()->unique()->numberBetween(1000, 99999), 'type' => CouponType::Percent, 'value' => 1000,
            'min_order_cents' => 0, 'is_active' => true, ...$attrs,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>, int, bool, int, bool}> */
    public static function examples(): iterable
    {
        yield 'BEMVINDO10 10%' => [['type' => CouponType::Percent, 'value' => 1000], 20000, true, 2000, false];
        yield 'DESC30 fixed min 150' => [['type' => CouponType::Fixed, 'value' => 3000, 'min_order_cents' => 15000], 20000, true, 3000, false];
        yield 'DESC30 below min' => [['type' => CouponType::Fixed, 'value' => 3000, 'min_order_cents' => 15000], 12000, false, 0, false];
        yield 'VALE500 capped to subtotal' => [['type' => CouponType::Fixed, 'value' => 50000], 20000, true, 20000, false];
        yield 'FRETEGRATIS' => [['type' => CouponType::FreeShipping, 'value' => 0], 20000, true, 0, true];
        yield 'MEGA15 cap 20' => [['type' => CouponType::Percent, 'value' => 1500, 'max_discount_cents' => 2000], 20000, true, 2000, false];
    }

    /** @param  array<string, mixed>  $attrs */
    #[DataProvider('examples')]
    public function test_examples(array $attrs, int $subtotal, bool $valid, int $discount, bool $free): void
    {
        $coupon = $this->coupon($attrs);
        $e = app(CouponService::class)->evaluate(' '.strtolower($coupon->code).' ', $this->ctx($subtotal));

        self::assertSame($valid, $e->valid);
        self::assertSame($discount, $e->discount->cents());
        self::assertSame($free, $e->freeShipping);
        if (! $valid) {
            self::assertSame('min_order_not_met', $e->reasonCode);
            self::assertSame('Pedido mínimo para este cupom: R$ 150,00.', $e->message);
        }
    }

    public function test_invalid_reasons(): void
    {
        $svc = app(CouponService::class);
        self::assertSame('not_found', $svc->evaluate('NOPE', $this->ctx(1000))->reasonCode);
        self::assertSame('inactive', $svc->evaluate($this->coupon(['is_active' => false])->code, $this->ctx(1000))->reasonCode);
        self::assertSame('inactive', $svc->evaluate($this->coupon(['starts_at' => now()->addDay()])->code, $this->ctx(1000))->reasonCode);
        self::assertSame('expired', $svc->evaluate($this->coupon(['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()])->code, $this->ctx(1000))->reasonCode);
        self::assertSame('login_required', $svc->evaluate($this->coupon(['usage_limit_per_customer' => 1])->code, $this->ctx(1000))->reasonCode);
    }

    public function test_redeem_enforces_limits_and_release_gives_usage_back(): void
    {
        $coupon = $this->coupon(['usage_limit' => 1, 'usage_limit_per_customer' => 1]);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $svc = app(CouponService::class);

        DB::transaction(fn () => $svc->redeem($coupon->code, $this->ctx(20000, $customer->id), $order->id));
        self::assertSame(1, $coupon->fresh()->times_used);
        self::assertDatabaseHas('coupon_redemptions', ['order_id' => $order->id, 'discount_cents' => 2000]);

        $other = Order::factory()->create(['customer_id' => $customer->id]);
        try {
            DB::transaction(fn () => $svc->redeem($coupon->code, $this->ctx(20000, $customer->id), $other->id));
            self::fail('Expected CouponInvalid');
        } catch (CouponInvalid $e) {
            self::assertSame('coupon_invalid', $e->errorCode());
            self::assertSame(409, $e->httpStatus());
            self::assertSame('usage_limit_reached', $e->details()['coupon']['reason_code']);
        }

        $svc->releaseForOrder($order->id);
        $svc->releaseForOrder($order->id); // idempotent
        self::assertSame(0, $coupon->fresh()->times_used);
        self::assertTrue($svc->evaluate($coupon->code, $this->ctx(20000, $customer->id))->valid);
    }

    public function test_per_customer_limit(): void
    {
        $coupon = $this->coupon(['usage_limit_per_customer' => 1]);
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        DB::transaction(fn () => app(CouponService::class)->redeem($coupon->code, $this->ctx(20000, $customer->id), $order->id));

        self::assertSame('customer_limit_reached', app(CouponService::class)->evaluate($coupon->code, $this->ctx(20000, $customer->id))->reasonCode);
        self::assertTrue(app(CouponService::class)->evaluate($coupon->code, $this->ctx(20000, Customer::factory()->create()->id))->valid);
    }

    public function test_redeem_requires_transaction(): void
    {
        $this->expectException(\LogicException::class);
        // RefreshDatabase opens a transaction, so simulate its absence by checking the guard directly.
        DB::rollBack();
        try {
            app(CouponService::class)->redeem('X', $this->ctx(1, 1), 1);
        } finally {
            DB::beginTransaction();
        }
    }
}
