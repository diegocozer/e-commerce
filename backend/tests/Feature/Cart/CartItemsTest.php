<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CartItemsTest extends TestCase
{
    use CartTestHelpers;
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_guest_adds_vinyl_5_m_and_gets_7950(): void
    {
        $response = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5]);

        $response->assertCreated()
            ->assertJsonPath('data.owner', 'guest')
            ->assertJsonPath('data.items.0.line_total_cents', 7950)
            ->assertJsonPath('data.items.0.unit_price_cents', 1590)
            ->assertJsonPath('data.items.0.price_source', 'base')
            ->assertJsonPath('data.items.0.configuration_label', '5 m')
            ->assertJsonPath('data.items.0.billable_quantity', 5)
            ->assertJsonPath('data.items.0.status', 'ok')
            ->assertJsonPath('data.items.0.rules.quantity_step', 0.1)
            ->assertJsonPath('data.totals.subtotal_cents', 7950)
            ->assertJsonPath('data.totals.total_cents', 7950)
            ->assertJsonPath('data.can_checkout', true);
        $token = $response->headers->get('X-Cart-Token');
        self::assertNotNull($token);
        self::assertSame($token, $response->json('data.token'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(1590, CartItem::query()->value('last_seen_unit_price_cents'));
    }

    public function test_banner_1_20_x_2_50_gets_9000(): void
    {
        $this->addItem(['variant_id' => $this->variantId('LON-FL-440-SM'), 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 1])
            ->assertCreated()
            ->assertJsonPath('data.items.0.sale_unit', 'SQUARE_METER')
            ->assertJsonPath('data.items.0.area_m2', 3)
            ->assertJsonPath('data.items.0.line_total_cents', 9000)
            ->assertJsonPath('data.items.0.configuration.width_m', 1.2)
            ->assertJsonPath('data.items.0.configuration.quantity', null)
            ->assertJsonPath('data.items.0.configuration_label', '1,20 m × 2,50 m × 1 peça');

        $item = CartItem::query()->firstOrFail();
        self::assertNull($item->quantity);
        self::assertSame([1200, 2500, 1], [$item->width_mm, $item->height_mm, $item->pieces]);
    }

    public function test_off_step_quantity_is_rejected_with_suggestions(): void
    {
        $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5.05])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity')
            ->assertJsonPath('details.quantity.suggestions', [5, 5.1]);

        self::assertSame(0, Cart::query()->count());
    }

    public function test_invalid_quantity_formats_and_inactive_variant(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        foreach ([0, -1, '5.1234', 'abc', null] as $quantity) {
            $this->addItem(['variant_id' => $vinyl, 'quantity' => $quantity])->assertUnprocessable();
        }
        $this->addItem(['variant_id' => $vinyl, 'quantity' => 60])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->addItem(['variant_id' => $this->variantId('LON-FL-440-SM'), 'width_m' => 1.205, 'height_m' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('width_m');

        ProductVariant::query()->whereKey($vinyl)->update(['is_active' => false]);
        $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])
            ->assertUnprocessable()->assertJsonPath('errors.variant_id.0', 'Produto indisponível.');
    }

    public function test_client_prices_are_prohibited_and_nothing_is_saved(): void
    {
        foreach (['unit_price_cents' => 1, 'price' => 1, 'line_total_cents' => 1, 'customer_id' => 1, 'discount_cents' => 0, 'status' => 'ok'] as $field => $value) {
            $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5, $field => $value])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        self::assertSame(0, CartItem::query()->count());
    }

    public function test_same_line_sums_and_revalidates_max_and_stock(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $token = $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])->json('data.token');

        $this->addItem(['variant_id' => $vinyl, 'quantity' => 5.5], $token)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.configuration.quantity', 10.5)
            // tier ≥ 10 m by the variant total (seed: R$ 14,90)
            ->assertJsonPath('data.items.0.unit_price_cents', 1490)
            ->assertJsonPath('data.items.0.price_source', 'tier');

        $this->addItem(['variant_id' => $vinyl, 'quantity' => 40], $token)
            ->assertUnprocessable()->assertJsonValidationErrors('quantity'); // 50.5 > max 50

        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 12, 'reserved' => 0]);
        $this->addItem(['variant_id' => $vinyl, 'quantity' => 2], $token)
            ->assertStatus(409)
            ->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('items.0.requested_quantity', 12.5)
            ->assertJsonPath('items.0.available_quantity', 12);
        self::assertSame('10.500', CartItem::query()->firstOrFail()->quantity->toDecimalString());
    }

    public function test_square_meter_lines_with_different_dimensions_are_distinct_and_patch_merges(): void
    {
        $lona = $this->variantId('LON-FL-440-SM');
        $token = $this->addItem(['variant_id' => $lona, 'width_m' => 1.2, 'height_m' => 2.5])->json('data.token');
        $cart = $this->addItem(['variant_id' => $lona, 'width_m' => 1, 'height_m' => 2, 'pieces' => 2], $token)
            ->assertCreated()->assertJsonCount(2, 'data.items')->json('data');
        $this->addItem(['variant_id' => $lona, 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 2], $token)
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.configuration.pieces', 3);

        $second = $cart['items'][1]['id'];
        $this->patchJson("/api/v1/cart/items/{$second}", ['width_m' => 1.2, 'height_m' => 2.5], ['X-Cart-Token' => $token])
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.configuration.pieces', 5)
            ->assertJsonPath('data.items.0.line_total_cents', 45000);
    }

    public function test_patch_delete_and_clear(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $data = $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])->json('data');
        $headers = ['X-Cart-Token' => $data['token']];
        $id = $data['items'][0]['id'];

        $this->patchJson("/api/v1/cart/items/{$id}", ['quantity' => '5.3'], $headers)
            ->assertOk()->assertJsonPath('data.items.0.line_total_cents', 8427);
        $this->patchJson("/api/v1/cart/items/{$id}", ['quantity' => 5, 'unit_price_cents' => 1], $headers)->assertUnprocessable();
        $this->deleteJson("/api/v1/cart/items/{$id}", [], $headers)->assertOk()->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.blocking_reasons', ['cart_empty']);
        $this->deleteJson("/api/v1/cart/items/{$id}", [], $headers)->assertNotFound()->assertJsonPath('code', 'not_found');

        $this->addItem(['variant_id' => $vinyl, 'quantity' => 2], $data['token']);
        $this->deleteJson('/api/v1/cart', [], $headers)->assertOk()->assertJsonPath('data.items', [])->assertJsonPath('data.token', $data['token']);
    }

    public function test_guest_token_isolation(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $a = $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])->json('data');
        $b = $this->addItem(['variant_id' => $vinyl, 'quantity' => 2])->json('data');
        self::assertNotSame($a['token'], $b['token']);

        // B cannot touch A's item through its own token
        $this->deleteJson("/api/v1/cart/items/{$a['items'][0]['id']}", [], ['X-Cart-Token' => $b['token']])->assertNotFound();
        $this->patchJson("/api/v1/cart/items/{$a['items'][0]['id']}", ['quantity' => 1], ['X-Cart-Token' => $b['token']])->assertNotFound();
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $a['token']])->assertOk()->assertJsonPath('data.items.0.configuration.quantity', 5);

        // unknown / malformed / customer-owned / expired tokens → 404 cart_not_found
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => '9b1f0c7e-3d2a-4e5b-8f6c-7a8b9c0d1e2f'])->assertNotFound()->assertJsonPath('code', 'cart_not_found');
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => 'nope'])->assertNotFound()->assertJsonPath('code', 'cart_not_found');
        Cart::query()->where('token', $b['token'])->update(['customer_id' => $this->maria()->id]);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $b['token']])->assertNotFound();
        Cart::query()->where('token', $a['token'])->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $a['token']])->assertNotFound();
    }

    public function test_get_without_token_returns_empty_cart_without_creating(): void
    {
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.token', null)->assertJsonPath('data.items', [])->assertJsonPath('data.can_checkout', false);
        self::assertSame(0, Cart::query()->count());
    }

    public function test_customer_cart_ignores_token_and_uses_session(): void
    {
        $guest = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');

        $this->actingAs($this->maria(), 'customer');
        $response = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-FO'), 'quantity' => 2], $guest)->assertCreated();
        $response->assertJsonPath('data.owner', 'customer')->assertJsonPath('data.token', null)->assertJsonCount(1, 'data.items');
        self::assertNull($response->headers->get('X-Cart-Token'));
        self::assertSame(1, Cart::query()->where('customer_id', $this->maria()->id)->count());
    }

    public function test_price_change_warning_and_acknowledge(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $token = $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])->json('data.token');
        ProductVariant::query()->whereKey($vinyl)->update(['price_cents' => 1650]);

        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.has_price_changes', true)
            ->assertJsonPath('data.can_checkout', true)
            ->assertJsonPath('data.items.0.line_total_cents', 8250)
            ->assertJsonPath('data.items.0.warnings.0', ['code' => 'price_changed', 'previous_unit_price_cents' => 1590, 'current_unit_price_cents' => 1650]);

        $this->postJson('/api/v1/cart/acknowledge-prices', [], ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.has_price_changes', false)->assertJsonPath('data.items.0.warnings', []);
    }

    public function test_unavailable_and_out_of_stock_lines_block_checkout(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $token = $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])->json('data.token');

        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 3, 'reserved' => 0]);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.items.0.status', 'insufficient_stock')
            ->assertJsonPath('data.items.0.warnings.0.available_quantity', 3)
            ->assertJsonPath('data.totals.subtotal_cents', 7950)
            ->assertJsonPath('data.blocking_reasons', ['item_insufficient_stock']);

        ProductVariant::query()->whereKey($vinyl)->update(['is_active' => false]);
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertOk()
            ->assertJsonPath('data.items.0.status', 'unavailable')
            ->assertJsonPath('data.items.0.unit_price_cents', null)
            ->assertJsonPath('data.totals.subtotal_cents', 0)
            ->assertJsonPath('data.can_checkout', false)
            ->assertJsonPath('data.blocking_reasons', ['item_unavailable']);
    }

    public function test_line_limit(): void
    {
        $token = $this->addItem(['variant_id' => $this->variantId('LON-FL-440-SM'), 'width_m' => 1, 'height_m' => 1])->json('data.token');
        $cart = Cart::query()->where('token', $token)->firstOrFail();
        for ($h = 2; $h <= 50; $h++) {
            $item = new CartItem(['variant_id' => $this->variantId('LON-FL-440-SM'), 'width_mm' => 1000, 'height_mm' => 1000 + $h * 10, 'pieces' => 1]);
            $item->cart_id = $cart->id;
            $item->save();
        }
        $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 1], $token)
            ->assertUnprocessable()->assertJsonValidationErrors('variant_id');
    }

    public function test_purge_command_removes_expired_guest_carts(): void
    {
        $token = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');
        $live = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');
        Cart::query()->where('token', $token)->update(['expires_at' => now()->subDay()]);

        $this->artisan('carts:prune')->assertSuccessful();

        self::assertFalse(Cart::query()->where('token', $token)->exists());
        self::assertTrue(Cart::query()->where('token', $live)->exists());
    }
}
