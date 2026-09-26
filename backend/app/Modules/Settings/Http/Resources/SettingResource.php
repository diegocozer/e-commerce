<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** API.md §2.16 Setting — every whitelisted key, stored row or default. */
final class SettingResource
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $rows = Setting::query()->get()->keyBy('key');
        $adminIds = $rows->pluck('updated_by')->filter()->unique()->values()->all();
        // Settings may not import Identity: admin names are read directly (read-only).
        $admins = $adminIds === [] ? [] : DB::table('admin_users')->whereIn('id', $adminIds)->pluck('name', 'id')->all();

        return array_map(static function (SettingKey $key) use ($rows, $admins): array {
            /** @var Setting|null $row */
            $row = $rows->get($key->value);

            return [
                'key' => $key->value,
                'value' => $row !== null ? $row->value : $key->defaultValue(),
                'type' => $key->type(),
                'group' => $key->group(),
                'is_public' => $key->isPublic(),
                'description' => $row?->description,
                'updated_at' => $row?->updated_at === null ? null : CarbonImmutable::instance($row->updated_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                'updated_by' => $row?->updated_by === null ? null : ['id' => (int) $row->updated_by, 'name' => (string) ($admins[$row->updated_by] ?? '')],
            ];
        }, SettingKey::cases());
    }
}
