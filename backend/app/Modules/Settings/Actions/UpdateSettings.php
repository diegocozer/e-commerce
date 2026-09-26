<?php

declare(strict_types=1);

namespace App\Modules\Settings\Actions;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Exceptions\StaleResource;
use App\Modules\Settings\Models\Setting;
use App\Modules\Settings\Support\SettingRules;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** PATCH /admin/settings: optimistic check, typed normalization, one audit entry per changed key. */
final class UpdateSettings
{
    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $values */
    public function handle(array $values, ActorRef $actor, ?string $expectedUpdatedAt): void
    {
        DB::transaction(function () use ($values, $actor, $expectedUpdatedAt): void {
            if ($expectedUpdatedAt !== null) {
                $current = Setting::query()->lockForUpdate()->get(['updated_at'])->max('updated_at');
                $currentIso = $current === null ? null : CarbonImmutable::instance($current)->utc();
                if ($currentIso !== null && ! $currentIso->equalTo(CarbonImmutable::parse($expectedUpdatedAt)->utc())) {
                    throw new StaleResource(details: ['current_updated_at' => $currentIso->format('Y-m-d\TH:i:s\Z')]);
                }
            }

            $changes = [];
            $old = [];
            foreach ($values as $raw => $value) {
                $key = SettingKey::from((string) $raw);
                $before = $this->settings->get($key);
                $normalized = SettingRules::normalize($key, $value, $before);
                if ($normalized !== $before) {
                    $changes[$key->value] = $normalized;
                    $old[$key->value] = $before;
                }
            }

            if ($changes === []) {
                return;
            }

            $this->settings->setMany($changes, $actor);

            $ids = Setting::query()->whereIn('key', array_keys($changes))->pluck('id', 'key');
            foreach ($changes as $key => $new) {
                $this->audit->record(new AuditEntry(
                    $actor, 'setting.updated', 'setting', (int) $ids[$key],
                    ['key' => $key, 'value' => $old[$key]], ['key' => $key, 'value' => $new],
                ));
            }
        });
    }
}
