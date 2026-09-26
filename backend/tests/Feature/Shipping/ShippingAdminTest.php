<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cart\Models\Cart;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Models\IbgeCity;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Shipping\Concerns\ActsAsShippingAdmin;
use Tests\Feature\Shipping\Concerns\BuildsShippingFixture;
use Tests\TestCase;

/** Admin shipping endpoints (API.md §3.G.11) + simulator (T52). */
final class ShippingAdminTest extends TestCase
{
    use ActsAsShippingAdmin;
    use BuildsShippingFixture;
    use RefreshDatabase;

    private const string API = '/api/v1/admin/shipping';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedShippingFixture();
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'carriers' => ['GET', '/carriers'], 'drivers' => ['GET', '/carriers/drivers'], 'methods' => ['GET', '/methods'],
            'zones' => ['GET', '/zones'], 'rules' => ['GET', '/rules'], 'cities' => ['GET', '/cities?search=blu'],
            'simulate' => ['POST', '/simulate'], 'create rule' => ['POST', '/rules'], 'reorder' => ['PUT', '/methods/reorder'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_requires_admin_session_and_shipping_manage(string $verb, string $path): void
    {
        $this->json($verb, self::API.$path)->assertStatus(401);
        $this->shippingAdmin(withPermission: false);
        $this->json($verb, self::API.$path)->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_customer_session_cannot_access_admin(): void
    {
        $this->actingAs(Customer::factory()->create(), 'customer');
        $this->getJson(self::API.'/methods')->assertStatus(401);
    }

    public function test_carrier_crud_keeps_credentials_write_only_and_out_of_audit(): void
    {
        $this->shippingAdmin();
        $id = $this->postJson(self::API.'/carriers', [
            'name' => 'Fake', 'code' => 'fake_x', 'driver' => 'fake', 'credentials' => ['token' => 'super-secret'],
            'settings' => ['timeout_ms' => 3000, 'origin_postal_code' => '89010-001', 'mode' => 'ok'], 'is_active' => true,
        ])->assertCreated()
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.settings.origin_postal_code', '89010001')
            ->assertJsonMissingPath('data.credentials')
            ->json('data.id');

        self::assertSame(['token' => 'super-secret'], ShippingCarrier::query()->findOrFail($id)->credentials);
        self::assertStringNotContainsString('super-secret', (string) DB::table('shipping_carriers')->where('id', $id)->value('credentials'));
        self::assertStringNotContainsString('super-secret', json_encode(AuditLog::query()->get()->toArray()));
        self::assertSame(1, AuditLog::query()->where('action', 'shipping_carrier.created')->count());

        $this->patchJson(self::API."/carriers/{$id}", ['code' => 'other'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->patchJson(self::API."/carriers/{$id}", ['credentials' => null])->assertOk()->assertJsonPath('data.has_credentials', false);
        $this->postJson(self::API.'/carriers', ['name' => 'X', 'code' => 'x', 'driver' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('driver');
        $this->postJson(self::API.'/carriers', ['name' => 'X', 'code' => 'x', 'driver' => 'fake', 'settings' => ['timeout_ms' => 100]])->assertStatus(422)->assertJsonValidationErrors('settings.timeout_ms');

        $this->getJson(self::API.'/carriers/drivers')->assertOk()->assertJsonPath('data.0.driver', 'fake');
        $this->postJson(self::API."/carriers/{$id}/test")->assertOk()->assertJsonPath('data.ok', true);

        $this->carrierMethod([], 'EXP', [], ShippingCarrier::query()->findOrFail($id));
        $this->deleteJson(self::API."/carriers/{$id}")->assertStatus(409)->assertJsonPath('code', 'resource_in_use')->assertJsonPath('blockers.0.type', 'shipping_method');
    }

    public function test_method_crud_type_immutable_defaults_and_reorder(): void
    {
        $this->shippingAdmin();
        $this->postJson(self::API.'/methods', ['name' => 'Tabela SC', 'code' => 'table-sc', 'type' => 'table_rate', 'delivery_days_min' => 2, 'delivery_days_max' => 5])
            ->assertCreated()->assertJsonPath('data.accepts_free_shipping_coupon', true)->assertJsonPath('data.pickup', null);
        $this->postJson(self::API.'/methods', ['name' => 'Retirada 2', 'code' => 'pickup-2', 'type' => 'pickup'])
            ->assertStatus(422)->assertJsonValidationErrors('pickup');
        $this->postJson(self::API.'/methods', ['name' => 'Car', 'code' => 'car', 'type' => 'carrier'])
            ->assertStatus(422)->assertJsonValidationErrors(['carrier_id', 'carrier_service_code']);
        $this->postJson(self::API.'/methods', ['name' => 'Dup', 'code' => 'own-delivery', 'type' => 'own_delivery'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson(self::API.'/methods', ['name' => 'Bad', 'code' => 'bad', 'type' => 'own_delivery', 'delivery_days_min' => 3, 'delivery_days_max' => 1])->assertStatus(422)->assertJsonValidationErrors('delivery_days_max');

        $own = $this->methods['own'];
        $this->patchJson(self::API."/methods/{$own->id}", ['type' => 'table_rate'])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->patchJson(self::API."/methods/{$own->id}", ['name' => 'Entrega expressa', 'expected_updated_at' => '2000-01-01T00:00:00Z'])
            ->assertStatus(409)->assertJsonPath('code', 'stale_resource');
        $this->patchJson(self::API."/methods/{$own->id}", ['name' => 'Entrega expressa'])->assertOk()->assertJsonPath('data.name', 'Entrega expressa')->assertJsonPath('data.rules_count', 4);

        $pickup = $this->methods['pickup'];
        $this->getJson(self::API."/methods/{$pickup->id}")->assertOk()->assertJsonPath('data.pickup.city', 'Blumenau');

        $ids = [$this->methods['cep']->id, $own->id, $pickup->id, $this->methods['regional']->id];
        $this->putJson(self::API.'/methods/reorder', ['ids' => $ids])->assertNoContent();
        self::assertSame([0, 10, 20, 30], array_map(fn (int $id) => ShippingMethod::query()->findOrFail($id)->position, $ids));

        $this->deleteJson(self::API."/methods/{$own->id}")->assertNoContent();
        self::assertSoftDeleted($own);
        self::assertTrue(AuditLog::query()->where('action', 'shipping_method.deleted')->exists());
    }

    public function test_zone_crud_nested_locations_warnings_test_and_delete_guard(): void
    {
        $this->shippingAdmin();
        IbgeCity::query()->create(['ibge_code' => '4202404', 'name' => 'Blumenau', 'state' => 'SC']);
        IbgeCity::query()->create(['ibge_code' => '4205902', 'name' => 'Gaspar', 'state' => 'SC']);

        $response = $this->postJson(self::API.'/zones', [
            'name' => 'Vale', 'postal_ranges' => [['start_postal_code' => '89010-000', 'end_postal_code' => '89012999']],
            'cities' => [['city_ibge_code' => '4202404'], ['city_ibge_code' => '4205902']], 'states' => ['SC'],
        ])->assertCreated()
            ->assertJsonPath('data.postal_ranges.0.start_postal_code', '89010000')
            ->assertJsonPath('data.cities.0.city_name', 'Blumenau')
            ->assertJsonPath('data.states', ['SC']);
        self::assertStringContainsString('sobrepõe', $response->json('warnings.0'));
        $id = $response->json('data.id');

        $this->patchJson(self::API."/zones/{$id}", ['cities' => [], 'postal_ranges' => [['start_postal_code' => '89020000', 'end_postal_code' => '89010000']]])
            ->assertStatus(422)->assertJsonValidationErrors('postal_ranges.0.end_postal_code');
        $this->patchJson(self::API."/zones/{$id}", ['cities' => [], 'states' => []])->assertOk()
            ->assertJsonPath('data.cities', [])->assertJsonCount(1, 'data.postal_ranges');
        $this->postJson(self::API.'/zones', ['name' => 'X', 'cities' => [['city_ibge_code' => '9999999']]])->assertStatus(422);

        $this->postJson(self::API."/zones/{$id}/test", ['postal_code' => '89010-100'])->assertOk()
            ->assertJsonPath('data.matches', true)->assertJsonPath('data.matched_by', 'postal_range:89010000-89012999')
            ->assertJsonPath('data.destination.city', 'Blumenau');
        $this->postJson(self::API."/zones/{$id}/test", ['postal_code' => self::SAO_PAULO])->assertOk()->assertJsonPath('data.matches', false);

        $this->deleteJson(self::API.'/zones/'.$this->zones['blumenau']->id)->assertStatus(409)
            ->assertJsonPath('code', 'resource_in_use')->assertJsonPath('blockers.0.type', 'shipping_rule');
        $this->deleteJson(self::API."/zones/{$id}")->assertNoContent();

        $this->getJson(self::API.'/cities?search=BLUM&state=sc')->assertOk()->assertJsonPath('data.0.ibge_code', '4202404')->assertJsonCount(1, 'data');
    }

    public function test_rule_crud_validation_warnings_duplicate_and_reorder(): void
    {
        $this->shippingAdmin();
        $base = ['method_id' => $this->methods['regional']->id, 'zone_id' => $this->zones['gaspar']->id, 'name' => 'Gaspar até 5 kg', 'priority' => 50];

        $this->postJson(self::API.'/rules', $base + ['price_type' => 'per_kg'])->assertStatus(422)->assertJsonValidationErrors('per_kg_cents');
        $this->postJson(self::API.'/rules', $base + ['price_type' => 'percentage_of_subtotal'])->assertStatus(422)->assertJsonValidationErrors('percentage_bp');
        $this->postJson(self::API.'/rules', $base + ['price_type' => 'fixed', 'price_cents' => 100, 'min_weight_grams' => 10, 'max_weight_grams' => 5])->assertStatus(422)->assertJsonValidationErrors('max_weight_grams');
        $this->postJson(self::API.'/rules', ['method_id' => $this->methods['pickup']->id] + $base + ['price_type' => 'fixed'])->assertStatus(422)->assertJsonValidationErrors('method_id');
        $this->postJson(self::API.'/rules', $base + ['price_type' => 'fixed', 'price_cents' => 100, 'valid_from' => '2026-10-02T00:00:00Z', 'valid_until' => '2026-10-01T00:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('valid_until');

        $created = $this->postJson(self::API.'/rules', $base + ['price_type' => 'fixed', 'price_cents' => 1900, 'max_weight_grams' => 10000, 'max_package_length_cm' => 132.5])
            ->assertCreated()
            ->assertJsonPath('data.summary', 'Gaspar · ≤ 10 kg → R$ 19,00')
            ->assertJsonPath('data.max_package_length_cm', 132.5)
            ->assertJsonPath('warnings', ['tie_broken_by_id']);
        $id = $created->json('data.id');

        $this->patchJson(self::API."/rules/{$id}", ['price_cents' => 2100])->assertOk()->assertJsonPath('data.price_cents', 2100);
        $dup = $this->postJson(self::API."/rules/{$id}/duplicate")->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.name', 'Gaspar até 5 kg (cópia)')->json('data.id');

        $this->postJson(self::API.'/rules/reorder', ['method_id' => $this->methods['regional']->id, 'zone_id' => $this->zones['gaspar']->id, 'ids' => [$dup, $id, $this->rules[210]->id]])
            ->assertOk()->assertJsonPath('data.0.id', $dup)->assertJsonPath('data.0.priority', 10)->assertJsonPath('data.2.priority', 30);

        $this->getJson(self::API.'/rules?method_id='.$this->methods['own']->id.'&zone_id=null')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->rules[103]->id);

        $free = $this->postJson(self::API.'/rules', ['method_id' => $this->methods['cep']->id, 'name' => 'Free global', 'priority' => 1, 'price_type' => 'free'])->assertCreated();
        self::assertSame(['rule_never_reachable'], array_values(array_diff($this->postJson(self::API.'/rules', ['method_id' => $this->methods['cep']->id, 'name' => 'Depois', 'priority' => 500, 'price_type' => 'fixed', 'price_cents' => 1])->json('warnings'), ['tie_broken_by_id'])));
        self::assertSame([], $free->json('warnings'));

        $this->deleteJson(self::API."/rules/{$id}")->assertNoContent();
        self::assertSoftDeleted(ShippingRule::withTrashed()->findOrFail($id));
        self::assertTrue(AuditLog::query()->where('action', 'shipping_rule.updated')->exists());
    }

    public function test_admin_changes_invalidate_engine_config(): void
    {
        $this->shippingAdmin();
        self::assertSame(2000, $this->prices($this->evaluate($this->shippingRequest()))['own-delivery']);
        $this->patchJson(self::API.'/rules/'.$this->rules[100]->id, ['price_cents' => 2222])->assertOk();
        self::assertSame(2222, $this->prices($this->evaluate($this->shippingRequest()))['own-delivery']);
    }

    public function test_t52_simulator_with_logistics_override_returns_trace(): void
    {
        $this->shippingAdmin();
        $response = $this->postJson(self::API.'/simulate', [
            'postal_code' => '89010-100',
            'logistics_override' => ['total_weight_grams' => 7200, 'total_volume_cm3' => 48210, 'largest_dimension_cm' => 132],
            'subtotal_cents' => 50000,
        ])->assertOk()
            ->assertJsonPath('data.destination.city_ibge_code', '4202404')
            ->assertJsonPath('data.logistics.total_weight_grams', 7200)
            ->assertJsonPath('data.logistics.cubic_weight_grams_6000', 8035);

        $methods = collect($response->json('data.methods'));
        $regional = $methods->firstWhere('code', 'table-regional');
        self::assertSame($this->rules[201]->id, $regional['winner_rule_id']);
        self::assertSame('weight_above_max', collect($regional['rules'])->firstWhere('rule_id', $this->rules[200]->id)['reasons'][0]['code']);
        self::assertSame(0, collect($response->json('data.options'))->firstWhere('method_code', 'own-delivery')['price_cents']);
        self::assertContains('city:4202404', array_column($response->json('data.zones_matched'), 'matched_by'));
    }

    public function test_simulator_validation_order_mode_and_filters(): void
    {
        $this->shippingAdmin();
        $this->postJson(self::API.'/simulate', ['postal_code' => '89010100'])->assertStatus(422)->assertJsonValidationErrors('items');
        $this->postJson(self::API.'/simulate', ['postal_code' => '89010100', 'order_id' => 1])->assertStatus(422)->assertJsonPath('errors.order_id.0', 'Pedido não encontrado.');
        $this->postJson(self::API.'/simulate', ['postal_code' => '123', 'logistics_override' => ['total_weight_grams' => 1, 'total_volume_cm3' => 1, 'largest_dimension_cm' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('postal_code');

        $response = $this->postJson(self::API.'/simulate', [
            'postal_code' => '89010100', 'method_ids' => [$this->methods['cep']->id], 'at' => '2030-01-01T00:00:00Z',
            'logistics_override' => ['total_weight_grams' => 1000, 'total_volume_cm3' => 1000, 'largest_dimension_cm' => 10],
        ])->assertOk();
        self::assertSame(['table-cep'], array_column($response->json('data.methods'), 'code'));
    }

    public function test_simulator_items_mode_uses_catalog_and_pricing(): void
    {
        $this->shippingAdmin();
        $variant = ProductVariant::factory()->create(['weight_grams' => 400]);

        $response = $this->postJson(self::API.'/simulate', ['postal_code' => '89010100', 'items' => [['variant_id' => $variant->id, 'quantity' => 3]]])
            ->assertOk()
            ->assertJsonPath('data.logistics.total_weight_grams', 1200)
            ->assertJsonPath('data.logistics.volumes_count', 3);
        self::assertGreaterThan(0, $response->json('data.subtotal_cents'));

        $this->postJson(self::API.'/simulate', ['postal_code' => '89010100', 'items' => [['variant_id' => 999999, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.variant_id');
    }

    public function test_quote_lookup_for_support(): void
    {
        $this->shippingAdmin();
        $cart = Cart::factory()->create();
        $quote = app(ShippingQuoteService::class)->quoteAndStore($this->shippingRequest(self::SAO_PAULO, cartId: $cart->id), []);

        $this->getJson(self::API.'/quotes/'.$quote->quoteId)->assertOk()
            ->assertJsonPath('data.quote_id', $quote->quoteId)
            ->assertJsonPath('data.unavailable.0.reason', 'out_of_coverage');
        $this->getJson(self::API.'/quotes/0b8f7c2e-6a61-4f3a-9d0e-3c9a4a1f2b10')->assertNotFound()->assertJsonPath('code', 'not_found');
    }
}
