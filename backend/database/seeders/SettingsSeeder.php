<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Models\Setting;
use Illuminate\Database\Seeder;

/** DATABASE.md §7.1 + API.md §3.G.13 whitelist: every known key with its default (existing values are kept). */
class SettingsSeeder extends Seeder
{
    public function run(SettingsRepository $settings): void
    {
        foreach (SettingKey::cases() as $key) {
            Setting::query()->firstOrCreate(['key' => $key->value], [
                'value' => $key->defaultValue(),
                'group' => $key->group(),
                'is_public' => $key->isPublic(),
            ]);
        }

        $settings->forgetCache();
    }
}
