<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Models\Coupon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Orders\Support\OrdersTestCase;

/** OrderPlacement with the real DatabaseCouponService: redemption on place, release on unpaid cancel. */
final class CouponIntegrationTest extends OrdersTestCase
{
    public function test_coupon_is_redeemed_and_released_on_cancel(): void
    {
        $coupon = Coupon::factory()->fixed(500)->create(['code' => 'DESC5']);
        $customer = Customer::factory()->create();
        $order = $this->placeOrder($customer, couponCode: 'DESC5', discountCents: 500);

        self::assertSame($coupon->id, $order->coupon_id);
        self::assertSame('DESC5', $order->coupon_code);
        self::assertSame(500, (int) $order->items()->sum('discount_cents'));
        self::assertSame(1, (int) $coupon->refresh()->times_used);

        $this->actingAs($customer, 'customer');
        $this->postJson("/api/v1/me/orders/{$order->uuid}/cancel")->assertOk();

        self::assertSame(0, (int) $coupon->refresh()->times_used);
        self::assertNotNull(DB::table('coupon_redemptions')->where('order_id', $order->id)->value('cancelled_at'));
    }
}
