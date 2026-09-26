<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cart\CartTestHelpers;
use Tests\TestCase;

/** POST /me/orders/{uuid}/reorder (API.md §3.D, RN-PED-040…045). */
final class ReorderTest extends TestCase
{
    use CartTestHelpers;
    use RefreshDatabase;

    protected bool $seed = true;

    private string $orderUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSeeded();
        $this->withoutMiddleware(ThrottleRequests::class);
        $maria = $this->maria();
        $this->actingAs($maria, 'customer');

        // order: vinyl 5 m + banner 1,20 × 2,50 × 2
        $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->assertSuccessful();
        $this->addItem(['variant_id' => $this->variantId('LON-FL-440-SM'), 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 2])->assertSuccessful();
        $address = $this->addressOf($maria)->uuid;
        $quote = $this->postJson('/api/v1/cart/shipping-quote', ['address_uuid' => $address])->assertOk()->json('data');
        $pickup = collect($quote['options'])->firstWhere('method_type', 'pickup');
        $this->orderUuid = $this->postJson('/api/v1/checkout', [
            'address_uuid' => $address, 'shipping_quote_id' => $quote['quote_id'], 'shipping_option_id' => $pickup['option_id'],
            'payment_method' => 'pix', 'expected_total_cents' => 7950 + 18000, 'accept_terms' => true,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.order.uuid');
    }

    private function reorder(): TestResponse
    {
        return $this->postJson("/api/v1/me/orders/{$this->orderUuid}/reorder");
    }

    public function test_reorder_adds_every_line_with_current_prices(): void
    {
        ProductVariant::query()->whereKey($this->variantId('VIN-BR-122-BR'))->update(['price_cents' => 1650]);

        $response = $this->reorder()->assertOk()
            ->assertJsonPath('data.summary', ['total_items' => 2, 'added_items' => 2, 'adjusted_items' => 0, 'skipped_items' => 0])
            ->assertJsonPath('data.items.0.result', 'added')
            ->assertJsonPath('data.items.0.previous_unit_price_cents', 1590)
            ->assertJsonPath('data.items.0.current_unit_price_cents', 1650)
            ->assertJsonPath('data.items.0.message', 'Adicionado. Preço atual R$ 16,50 (no pedido anterior: R$ 15,90).')
            ->assertJsonPath('data.items.0.requested', ['quantity' => 5, 'width_m' => null, 'height_m' => null, 'pieces' => null])
            ->assertJsonPath('data.items.1.added', ['quantity' => null, 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 2])
            ->assertJsonPath('data.items.1.product_url_path', '/lonas/lona-frontlight-440g')
            ->assertJsonPath('data.cart.owner', 'customer')
            ->assertJsonCount(2, 'data.cart.items')
            ->assertJsonPath('data.cart.totals.subtotal_cents', 8250 + 18000);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        // sums by line identity on a second reorder
        $this->reorder()->assertOk()->assertJsonCount(2, 'data.cart.items')
            ->assertJsonPath('data.cart.items.0.configuration.quantity', 10)
            ->assertJsonPath('data.cart.items.1.configuration.pieces', 4);
    }

    public function test_unavailable_invalid_rules_and_short_stock(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 3.35, 'reserved' => 0]);
        Product::query()->where('slug', 'lona-frontlight-440g')->update(['max_quantity' => 1]); // 2 pieces no longer allowed

        $this->reorder()->assertOk()
            ->assertJsonPath('data.summary', ['total_items' => 2, 'added_items' => 1, 'adjusted_items' => 1, 'skipped_items' => 1])
            ->assertJsonPath('data.items.0.result', 'adjusted')
            ->assertJsonPath('data.items.0.added.quantity', 3.3)
            ->assertJsonPath('data.items.1.result', 'invalid_rules')
            ->assertJsonPath('data.items.1.added', null)
            ->assertJsonCount(1, 'data.cart.items');

        ProductVariant::query()->whereKey($vinyl)->update(['is_active' => false]);
        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 500]);
        $this->reorder()->assertOk()
            ->assertJsonPath('data.items.0.result', 'unavailable')
            ->assertJsonPath('data.items.0.message', 'Indisponível: Vinil Adesivo Branco.')
            ->assertJsonPath('data.items.0.product_url_path', null)
            ->assertJsonPath('data.items.0.current_unit_price_cents', null);
    }

    public function test_stock_below_minimum_is_not_added(): void
    {
        DB::table('inventory')->where('variant_id', $this->variantId('VIN-BR-122-BR'))->update(['on_hand' => 0.5, 'reserved' => 0]);

        $this->reorder()->assertOk()->assertJsonPath('data.items.0.result', 'unavailable')
            ->assertJsonPath('data.items.0.message', 'Sem estoque suficiente para Vinil Adesivo Branco.');
    }

    public function test_idor_and_authentication(): void
    {
        $this->actingAs($this->joao(), 'customer');
        $this->reorder()->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/me/orders/'.Str::uuid().'/reorder')->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/me/orders/{$this->orderUuid}/reorder")->assertUnauthorized();
    }
}
