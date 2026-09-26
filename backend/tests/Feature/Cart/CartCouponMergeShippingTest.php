<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Customers\Contracts\GuestCartMerger;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CartCouponMergeShippingTest extends TestCase
{
    use CartTestHelpers;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_coupon_apply_valid_then_invalid_422_and_remove(): void
    {
        $token = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 20])->json('data.token'); // 20 × 1490 = 29800
        $headers = ['X-Cart-Token' => $token];

        $this->putJson('/api/v1/cart/coupon', ['code' => 'desc20'], $headers)->assertOk()
            ->assertJsonPath('data.coupon.valid', true)
            ->assertJsonPath('data.coupon.discount_cents', 2000)
            ->assertJsonPath('data.totals.discount_cents', 2000)
            ->assertJsonPath('data.totals.total_cents', 27800);

        $this->putJson('/api/v1/cart/coupon', ['code' => 'NAOEXISTE'], $headers)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/cart', $headers)->assertJsonPath('data.coupon.code', 'DESC20'); // not replaced by the invalid one
        $this->putJson('/api/v1/cart/coupon', ['code' => 'DESC20', 'discount_cents' => 99999], $headers)->assertUnprocessable()->assertJsonValidationErrors('discount_cents');

        $this->deleteJson('/api/v1/cart/coupon', [], $headers)->assertOk()->assertJsonPath('data.coupon', null)->assertJsonPath('data.totals.total_cents', 29800);
    }

    public function test_guest_per_customer_coupon_is_stored_as_login_required(): void
    {
        $token = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');
        $this->putJson('/api/v1/cart/coupon', ['code' => 'BEMVINDO10'], ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.coupon.valid', false)
            ->assertJsonPath('data.coupon.reason_code', 'login_required');
    }

    public function test_merge_on_login_sums_lines_clamps_to_max_and_inherits_coupon(): void
    {
        $maria = $this->maria();
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $lona = $this->variantId('LON-FL-440-SM');

        // customer cart: vinyl 30 m
        $this->actingAs($maria, 'customer');
        $this->addItem(['variant_id' => $vinyl, 'quantity' => 30])->assertCreated();
        $this->app['auth']->forgetGuards();

        // guest cart: vinyl 25 m (sum 55 > max 50) + lona + coupon for customers
        $guest = $this->addItem(['variant_id' => $vinyl, 'quantity' => 25])->json('data.token');
        $this->addItem(['variant_id' => $lona, 'width_m' => 1.2, 'height_m' => 2.5], $guest);
        $this->putJson('/api/v1/cart/coupon', ['code' => 'BEMVINDO10'], ['X-Cart-Token' => $guest])->assertOk();

        $report = $this->app->make(GuestCartMerger::class)->merge($guest, $maria->id);

        self::assertNotNull($report);
        self::assertTrue($report->merged);
        self::assertSame(1, $report->linesAdded);
        self::assertSame(1, $report->linesCombined);
        self::assertSame([[
            'variant_id' => $vinyl, 'sku' => 'VIN-BR-122-BR', 'product_name' => 'Vinil Adesivo Branco',
            'previous_quantity' => 55, 'quantity' => 50, 'reason' => 'max_quantity',
        ]], $report->adjustments);
        self::assertSame(['code' => 'BEMVINDO10', 'kept' => true, 'reason_code' => null], $report->coupon);
        self::assertFalse(Cart::query()->where('token', $guest)->exists());

        $cart = Cart::query()->where('customer_id', $maria->id)->whereNull('converted_at')->firstOrFail();
        self::assertSame(2, $cart->items()->count());
        self::assertNotNull($cart->coupon_id);
        self::assertSame('50.000', CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $vinyl)->firstOrFail()->quantity->toDecimalString());

        // the guest token is gone: reuse → 404
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $guest])->assertNotFound();
    }

    public function test_merge_without_customer_cart_moves_items_and_reports_stock_and_unavailable(): void
    {
        $joao = $this->joao();
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $fosco = $this->variantId('VIN-BR-122-FO');
        $guest = $this->addItem(['variant_id' => $vinyl, 'quantity' => 20])->json('data.token');
        $this->addItem(['variant_id' => $fosco, 'quantity' => 2], $guest);
        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 12.35, 'reserved' => 0]);
        DB::table('product_variants')->where('id', $fosco)->update(['is_active' => false]);

        $report = $this->app->make(CartService::class)->mergeGuestCart($guest, $joao->id);

        self::assertSame(1, $report->linesAdded);
        self::assertSame('insufficient_stock', $report->adjustments[0]['reason']);
        self::assertSame(12.3, $report->adjustments[0]['quantity']);
        self::assertSame('unavailable', $report->dropped[0]['reason']);
        self::assertNull($this->app->make(GuestCartMerger::class)->merge('9b1f0c7e-3d2a-4e5b-8f6c-7a8b9c0d1e2f', $joao->id));
        self::assertNull($this->app->make(GuestCartMerger::class)->merge(null, $joao->id));
    }

    public function test_logged_in_price_list_is_applied_after_merge(): void
    {
        $joao = $this->joao(); // PJ with the reseller list in the seed
        $guest = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');
        $this->app->make(GuestCartMerger::class)->merge($guest, $joao->id);

        $response = $this->actingAs($joao, 'customer')->getJson('/api/v1/cart')->assertOk();
        self::assertLessThanOrEqual(1590, $response->json('data.items.0.unit_price_cents'));
        self::assertNotNull($response->json('data.price_list'));
    }

    public function test_shipping_quote_by_postal_code_and_selection_in_get_cart(): void
    {
        $token = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');
        $headers = ['X-Cart-Token' => $token];

        $quote = $this->postJson('/api/v1/cart/shipping-quote', ['postal_code' => '89010-000'], $headers)
            ->assertOk()
            ->assertJsonPath('data.destination.postal_code', '89010000')
            ->assertJsonPath('data.total_weight_grams', 1250)
            ->json('data');
        self::assertNotEmpty($quote['options']);
        self::assertSame('89010000', Cart::query()->where('token', $token)->value('postal_code'));

        $option = $quote['options'][count($quote['options']) - 1];
        $this->getJson('/api/v1/cart?shipping_quote_id='.$quote['quote_id'].'&shipping_option_id='.urlencode($option['option_id']), $headers)
            ->assertOk()
            ->assertJsonPath('data.shipping_selection.valid', true)
            ->assertJsonPath('data.totals.shipping_cents', $option['price_cents'])
            ->assertJsonPath('data.totals.total_cents', 7950 + $option['price_cents']);

        $this->travel(31)->minutes();
        $this->getJson('/api/v1/cart?shipping_quote_id='.$quote['quote_id'].'&shipping_option_id='.urlencode($option['option_id']), $headers)
            ->assertOk()
            ->assertJsonPath('data.shipping_selection.valid', false)
            ->assertJsonPath('data.shipping_selection.issue_code', 'shipping_quote_expired')
            ->assertJsonPath('data.totals.shipping_cents', null);
    }

    public function test_shipping_quote_errors(): void
    {
        $this->postJson('/api/v1/cart/shipping-quote', ['postal_code' => '89010-000'])->assertStatus(409)->assertJsonPath('code', 'cart_empty');
        $token = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');
        $this->postJson('/api/v1/cart/shipping-quote', ['postal_code' => '123'], ['X-Cart-Token' => $token])->assertUnprocessable();

        // another customer's address → 422 "Endereço inválido."
        $joaoAddress = CustomerAddress::query()->where('customer_id', $this->joao()->id)->firstOrFail();
        $this->actingAs($this->maria(), 'customer');
        $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5]);
        $this->postJson('/api/v1/cart/shipping-quote', ['address_uuid' => $joaoAddress->uuid])
            ->assertUnprocessable()->assertJsonPath('errors.address_uuid.0', 'Endereço inválido.');
        $this->postJson('/api/v1/cart/shipping-quote', ['address_uuid' => $this->addressOf($this->maria())->uuid])
            ->assertOk()->assertJsonPath('data.destination.postal_code', '89012000');
    }
}
