<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Shared\Domain\ActorRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ProductEstimateAndAvailabilityTest extends TestCase
{
    use CartTestHelpers;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSeeded();
    }

    public function test_product_estimate_vinyl_5_m_is_not_persisted(): void
    {
        $quotesBefore = DB::table('shipping_quotes')->count();

        $data = $this->postJson('/api/v1/shipping/quote', [
            'postal_code' => '89010-000',
            'items' => [['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5]],
        ])->assertOk()
            ->assertJsonPath('data.quote_id', null)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.destination.postal_code', '89010000')
            ->assertJsonPath('data.total_weight_grams', 1250)
            ->json('data');

        // ids depend on the seed order; compare by method code (API.md §4.5 values)
        self::assertSame(['pickup-store' => 0, 'table-regional' => 1500, 'table-cep' => 1800, 'own-delivery' => 2000],
            collect($data['options'])->mapWithKeys(fn (array $o) => [$o['method_code'] => $o['price_cents']])->all());
        self::assertSame($quotesBefore, DB::table('shipping_quotes')->count());
    }

    public function test_product_estimate_square_meter_and_validation(): void
    {
        $this->postJson('/api/v1/shipping/quote', [
            'postal_code' => '89010000',
            'items' => [['variant_id' => $this->variantId('LON-FL-440-SM'), 'width_m' => 1.2, 'height_m' => 2.5, 'pieces' => 1]],
        ])->assertOk()->assertJsonPath('data.quote_id', null);

        $this->postJson('/api/v1/shipping/quote', ['postal_code' => '123', 'items' => [['variant_id' => 1, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonPath('errors.postal_code.0', 'CEP inválido.');
        $this->postJson('/api/v1/shipping/quote', ['postal_code' => '89010000', 'items' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/shipping/quote', ['postal_code' => '89010000', 'items' => array_fill(0, 11, ['variant_id' => 1, 'quantity' => 1])])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson('/api/v1/shipping/quote', [
            'postal_code' => '89010000',
            'items' => [
                ['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5],
                ['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5.05],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.quantity')->assertJsonPath('details', ['items.1.quantity' => ['suggestions' => [5, 5.1]]]);
        $this->postJson('/api/v1/shipping/quote', ['postal_code' => '89010000', 'items' => [['variant_id' => 999999, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.variant_id');
        $this->postJson('/api/v1/shipping/quote', ['postal_code' => '89010000', 'subtotal_cents' => 1,
            'items' => [['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors('subtotal_cents');
    }

    public function test_product_estimate_rate_limit(): void
    {
        $body = ['postal_code' => '123', 'items' => [['variant_id' => 1, 'quantity' => 1]]];
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/shipping/quote', $body)->assertUnprocessable();
        }
        $this->postJson('/api/v1/shipping/quote', $body)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    public function test_low_stock_availability_uses_the_threshold(): void
    {
        $vinyl = $this->variantId('VIN-BR-122-BR');
        $token = $this->addItem(['variant_id' => $vinyl, 'quantity' => 5])->json('data.token');
        $headers = ['X-Cart-Token' => $token];

        $this->getJson('/api/v1/cart', $headers)->assertJsonPath('data.items.0.availability', ['status' => 'in_stock', 'available_quantity' => null]);

        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 30, 'reserved' => 0, 'low_stock_threshold' => 50]);
        $this->getJson('/api/v1/cart', $headers)->assertJsonPath('data.items.0.availability', ['status' => 'low_stock', 'available_quantity' => 30]);

        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 30, 'reserved' => 0, 'low_stock_threshold' => null]);
        $this->getJson('/api/v1/cart', $headers)->assertJsonPath('data.items.0.availability.status', 'in_stock'); // default threshold 10

        DB::table('inventory')->where('variant_id', $vinyl)->update(['on_hand' => 0.5, 'reserved' => 0]);
        $this->getJson('/api/v1/cart', $headers)->assertJsonPath('data.items.0.availability', ['status' => 'out_of_stock', 'available_quantity' => null]);
    }

    public function test_free_shipping_progress_from_the_banner_setting(): void
    {
        $token = $this->addItem(['variant_id' => $this->variantId('VIN-BR-122-BR'), 'quantity' => 5])->json('data.token');

        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertJsonPath('data.free_shipping_progress', [
            'threshold_cents' => 50000, 'remaining_cents' => 42050, 'text' => 'Frete grátis na região de Blumenau acima de R$ 500',
        ]);

        $this->app->make(SettingsRepository::class)->set(SettingKey::StorefrontFreeShippingBanner,
            ['enabled' => false, 'threshold_cents' => 50000, 'text' => 'x'], ActorRef::system());
        $this->getJson('/api/v1/cart', ['X-Cart-Token' => $token])->assertJsonPath('data.free_shipping_progress', null);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.free_shipping_progress', null);
    }
}
