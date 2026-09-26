<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Enums\AdminPermission as P;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Settings\Contracts\PickupPointProvider;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Events\SettingsUpdated;
use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Identity\Support\ApiTestCase;

final class SettingsTest extends ApiTestCase
{
    public function test_public_settings_expose_only_public_data(): void
    {
        $this->app->instance(PickupPointProvider::class, new class implements PickupPointProvider
        {
            public function activePickupPoints(): array
            {
                return [['method_code' => 'retirada', 'name' => 'Retirada na loja', 'street' => 'Rua XV', 'number' => '1000', 'complement' => null,
                    'district' => 'Centro', 'city' => 'Blumenau', 'state' => 'SC', 'postal_code' => '89010001', 'opening_hours' => null, 'instructions' => null]];
            }
        });

        $response = $this->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('data.store.name', 'CV Suprimentos')
            ->assertJsonPath('data.store.address.city', 'Blumenau')
            ->assertJsonMissingPath('data.store.address.city_ibge_code')
            ->assertJsonPath('data.checkout.payment_methods', ['pix'])
            ->assertJsonPath('data.checkout.pix_expiry_minutes', 30)
            ->assertJsonPath('data.checkout.min_order_cents', 0)
            ->assertJsonPath('data.terms_version', '2026-01')
            ->assertJsonPath('data.free_shipping_banner.threshold_cents', 50000)
            ->assertJsonPath('data.features.show_low_stock_quantity', false)
            ->assertJsonPath('data.pickup_points.0.method_code', 'retirada');

        $body = (string) $response->getContent();
        self::assertStringNotContainsString('11222333000181', $body, 'store.document is private');
        self::assertStringNotContainsString('admin_alert_emails', $body);
    }

    public function test_pages(): void
    {
        app(SettingsRepository::class)->set(SettingKey::ContentAbout, "Primeiro parágrafo.\n\nSegundo.");

        $this->getJson('/api/v1/pages/sobre')->assertOk()
            ->assertJsonPath('data.slug', 'sobre')
            ->assertJsonPath('data.title', 'Sobre nós')
            ->assertJsonPath('data.body_text', "Primeiro parágrafo.\n\nSegundo.")
            ->assertJsonPath('data.updated_at', fn ($v) => is_string($v) && str_ends_with($v, 'Z'));
        $this->getJson('/api/v1/pages/termos')->assertOk()->assertJsonPath('data.updated_at', null);
        $this->assertApiError($this->getJson('/api/v1/pages/segredos'), 404, 'not_found');
    }

    public function test_admin_settings_require_permission(): void
    {
        $this->actingAsAdmin([], AdminRole::Seller);
        $this->assertApiError($this->getJson('/api/v1/admin/settings'), 403, 'forbidden');
        $this->assertApiError($this->patchJson('/api/v1/admin/settings', ['values' => ['store.name' => 'X Loja']]), 403, 'forbidden');
    }

    public function test_lists_every_whitelisted_key(): void
    {
        $this->actingAsAdmin([P::SettingsManage]);
        $data = $this->getJson('/api/v1/admin/settings')->assertOk()->assertJsonCount(count(SettingKey::cases()), 'data')->json('data');
        $byKey = array_column($data, null, 'key');
        self::assertSame('string', $byKey['store.name']['type']);
        self::assertSame('store', $byKey['store.name']['group']);
        self::assertFalse($byKey['store.document']['is_public']);
        self::assertSame(['pix' => 30, 'boleto' => 4320, 'credit_card' => 30, 'invoice' => 10080], $byKey['checkout.payment_expiry_minutes']['value']);
        self::assertNull($byKey['store.name']['updated_by']);
    }

    public function test_updates_settings_with_audit_and_cache_invalidation(): void
    {
        Event::fake([SettingsUpdated::class]);
        $admin = $this->actingAsAdmin([P::SettingsManage]);

        $data = $this->patchJson('/api/v1/admin/settings', ['values' => [
            'store.name' => 'Comunika Suprimentos',
            'store.document' => '11.222.333/0001-81',
            'checkout.payment_expiry_minutes' => ['pix' => 45],
            'inventory.show_low_stock_quantity' => true,
            'notifications.admin_alert_emails' => ['Ops@Example.com'],
            'content.terms' => 'Novos termos.',
        ]])->assertOk()->json('data');
        $byKey = array_column($data, null, 'key');

        self::assertSame('Comunika Suprimentos', $byKey['store.name']['value']);
        self::assertSame(['id' => $admin->id, 'name' => $admin->name], $byKey['store.name']['updated_by']);
        self::assertSame(45, $byKey['checkout.payment_expiry_minutes']['value']['pix']);
        self::assertSame(4320, $byKey['checkout.payment_expiry_minutes']['value']['boleto']);
        self::assertSame(['ops@example.com'], $byKey['notifications.admin_alert_emails']['value']);
        self::assertSame('Comunika Suprimentos', app(SettingsRepository::class)->get(SettingKey::StoreName));

        $this->getJson('/api/v1/settings/public')->assertJsonPath('data.store.name', 'Comunika Suprimentos')
            ->assertJsonPath('data.checkout.pix_expiry_minutes', 45);

        $log = AuditLog::query()->where('action', 'setting.updated')->where('new_values->key', 'store.name')->firstOrFail();
        self::assertSame('CV Suprimentos', $log->old_values['value']);
        self::assertSame($admin->id, $log->actor_id);
        self::assertSame('setting', $log->auditable_type);
        Event::assertDispatched(SettingsUpdated::class);
    }

    public function test_validation_per_key(): void
    {
        $this->actingAsAdmin([P::SettingsManage]);

        $cases = [
            'store.name' => 'X',
            'store.document' => '11.222.333/0001-80',
            'store.phone' => '123',
            'store.email' => 'nope',
            'store.social_links' => ['instagram' => 'http://insecure.example.com', 'facebook' => null, 'youtube' => null],
            'store.address' => ['street' => 'Rua', 'number' => '1', 'district' => 'C', 'city' => 'B', 'state' => 'XX', 'postal_code' => '89010001', 'city_ibge_code' => '4202404'],
            'orders.number_prefix' => 'cv',
            'checkout.payment_expiry_minutes' => ['pix' => 2],
            'checkout.min_order_cents' => -1,
            'cart.guest_ttl_days' => 91,
            'shipping.quote_ttl_minutes' => 4,
            'shipping.origin_postal_code' => '123',
            'inventory.default_low_stock_threshold' => '1.2345',
            'inventory.show_low_stock_quantity' => 'maybe',
            'storefront.free_shipping_banner' => ['enabled' => true, 'threshold_cents' => -5, 'text' => 'x'],
            'notifications.admin_alert_emails' => ['not-an-email'],
            'content.about' => '<script>alert(1)</script>',
        ];
        foreach ($cases as $key => $value) {
            $response = $this->patchJson('/api/v1/admin/settings', ['values' => [$key => $value]])->assertUnprocessable();
            self::assertNotEmpty(array_filter(array_keys($response->json('errors')), fn ($k) => str_starts_with($k, 'values.'.$key)), $key);
        }

        $this->patchJson('/api/v1/admin/settings', ['values' => ['app.debug' => true]])->assertJsonValidationErrors('values.app.debug');
        $this->patchJson('/api/v1/admin/settings', ['values' => []])->assertJsonValidationErrors('values');
        self::assertSame(0, Setting::query()->count());
    }

    public function test_stale_resource(): void
    {
        $this->actingAsAdmin([P::SettingsManage]);
        $this->patchJson('/api/v1/admin/settings', ['values' => ['store.name' => 'Primeira Loja']])->assertOk();
        $current = collect($this->getJson('/api/v1/admin/settings')->json('data'))->pluck('updated_at')->filter()->max();

        $this->travel(1)->minutes();
        $this->assertApiError($this->patchJson('/api/v1/admin/settings', ['values' => ['store.name' => 'Outra'], 'expected_updated_at' => '2020-01-01T00:00:00Z']), 409, 'stale_resource')
            ->assertJsonPath('current_updated_at', $current);
        $this->patchJson('/api/v1/admin/settings', ['values' => ['store.name' => 'Segunda Loja'], 'expected_updated_at' => $current])->assertOk();
        self::assertSame('Segunda Loja', app(SettingsRepository::class)->get(SettingKey::StoreName));
    }
}
