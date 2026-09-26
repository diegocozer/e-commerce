<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestHelpers;
use Tests\TestCase;

/** GET /me/reorder-suggestions (API.md §3.D). */
final class ReorderSuggestionsTest extends TestCase
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

    private function markPaid(): void
    {
        DB::table('orders')->where('uuid', $this->orderUuid)->update(['status' => 'paid', 'payment_status' => 'approved', 'paid_at' => now()]);
    }

    public function test_only_paid_orders_are_suggested_with_current_prices(): void
    {
        $this->getJson('/api/v1/me/reorder-suggestions')->assertOk()->assertExactJson(['data' => []]);

        $this->markPaid();
        ProductVariant::query()->whereKey($this->variantId('VIN-BR-122-BR'))->update(['price_cents' => 1650]);

        $response = $this->getJson('/api/v1/me/reorder-suggestions')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.variant.sku', 'VIN-BR-122-BR')
            ->assertJsonPath('data.0.product.url_path', '/vinis/vinil-adesivo-branco-122m')
            ->assertJsonPath('data.0.product.sale_unit', 'LINEAR_METER')
            ->assertJsonPath('data.0.last_configuration', ['quantity' => 5, 'width_m' => null, 'height_m' => null, 'pieces' => null])
            ->assertJsonPath('data.0.last_configuration_label', '5 m')
            ->assertJsonPath('data.0.current_unit_price_cents', 1650)
            ->assertJsonPath('data.0.price_source', 'base')
            ->assertJsonPath('data.0.availability.status', 'in_stock')
            ->assertJsonPath('data.1.last_configuration', ['quantity' => null, 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 2])
            ->assertJsonStructure(['data' => [['variant_id', 'product' => ['id', 'slug', 'name', 'url_path', 'image', 'sale_unit', 'sale_unit_abbr'],
                'variant' => ['id', 'sku', 'name'], 'last_configuration', 'last_configuration_label', 'last_ordered_at',
                'current_unit_price_cents', 'price_source', 'availability' => ['status']]]]);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $this->getJson('/api/v1/me/reorder-suggestions?limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me/reorder-suggestions?limit=13')->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_unavailable_variants_are_excluded_and_other_customers_see_nothing(): void
    {
        $this->markPaid();
        $vinyl = $this->variantId('VIN-BR-122-BR');
        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 0, 'reserved' => 0]);
        ProductVariant::query()->whereKey($this->variantId('LON-FL-440-SM'))->update(['is_active' => false]);
        $this->getJson('/api/v1/me/reorder-suggestions')->assertOk()->assertJsonCount(0, 'data');

        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 100]);
        $this->getJson('/api/v1/me/reorder-suggestions')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->joao(), 'customer');
        $this->getJson('/api/v1/me/reorder-suggestions')->assertOk()->assertJsonCount(0, 'data');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me/reorder-suggestions')->assertUnauthorized();
    }
}
