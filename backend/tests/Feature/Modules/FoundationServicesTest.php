<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Events\SettingsUpdated;
use App\Providers\AppServiceProvider;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use App\Shared\Support\SensitiveData;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class FoundationServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logger_masks_sensitive_values(): void
    {
        app(AuditLogger::class)->record(new AuditEntry(
            ActorRef::admin(7),
            'customer.updated',
            'customer',
            42,
            ['cpf' => '52998224725', 'name' => 'A'],
            ['password' => 'new-secret', 'name' => 'B'],
        ));

        $log = AuditLog::query()->sole();
        self::assertSame(ActorType::Admin, $log->actor_type);
        self::assertSame(7, $log->actor_id);
        self::assertSame('***.982.247-**', $log->old_values['cpf']);
        self::assertSame(SensitiveData::REDACTED, $log->new_values['password']);
        self::assertSame('B', $log->new_values['name']);
    }

    public function test_settings_repository_typed_access_cache_and_events(): void
    {
        Event::fake([SettingsUpdated::class]);
        $settings = app(SettingsRepository::class);
        $admin = AdminUser::factory()->create();

        self::assertSame(30, $settings->int(SettingKey::CheckoutPixExpiryMinutes)); // default without rows
        self::assertSame('CV-', $settings->string(SettingKey::OrdersNumberPrefix));

        $settings->set(SettingKey::CheckoutPixExpiryMinutes, 45, ActorRef::admin($admin->id));
        self::assertSame(45, $settings->int(SettingKey::CheckoutPixExpiryMinutes));
        self::assertSame($admin->id, DB::table('settings')->where('key', 'checkout.pix_expiry_minutes')->value('updated_by'));

        $settings->set(SettingKey::StoreWhatsapp, null);
        self::assertNull($settings->get(SettingKey::StoreWhatsapp));
        self::assertSame('SC', $settings->array(SettingKey::StoreAddress)['state']);
        self::assertArrayHasKey('store.name', $settings->public());
        self::assertArrayNotHasKey('store.document', $settings->public());

        Event::assertDispatched(SettingsUpdated::class, fn (SettingsUpdated $e): bool => $e->keys === ['checkout.pix_expiry_minutes']);
    }

    public function test_morph_map_is_enforced(): void
    {
        self::assertSame(AppServiceProvider::MORPH_MAP['order'], Relation::getMorphedModel('order'));
        self::assertSame('admin_user', (new AdminUser)->getMorphClass());
    }

    public function test_every_model_factory_creates_a_valid_row(): void
    {
        $models = [];
        foreach (glob(app_path('Modules/*/Models/*.php')) ?: [] as $file) {
            $module = basename(dirname($file, 2));
            $models[] = "App\\Modules\\{$module}\\Models\\".basename($file, '.php');
        }

        self::assertGreaterThanOrEqual(35, count($models));
        foreach ($models as $model) {
            $instance = $model::factory()->create();
            self::assertTrue($instance->exists, $model);
        }
    }
}
