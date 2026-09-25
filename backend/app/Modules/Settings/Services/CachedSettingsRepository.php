<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Events\SettingsUpdated;
use App\Modules\Settings\Models\Setting;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Settings read from a single cached map (`settings:all`, no TTL) that is
 * invalidated on every write (ARCHITECTURE.md §6.1).
 */
final class CachedSettingsRepository implements SettingsRepository
{
    public const string CACHE_KEY = 'settings:all';

    public function __construct(private readonly Cache $cache, private readonly Dispatcher $events) {}

    public function get(SettingKey $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key->value, $stored) ? $stored[$key->value] : $key->defaultValue();
    }

    public function string(SettingKey $key): ?string
    {
        $value = $this->get($key);

        return $value === null ? null : (string) $value;
    }

    public function int(SettingKey $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(SettingKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function array(SettingKey $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }

    public function set(SettingKey $key, mixed $value, ?ActorRef $actor = null): void
    {
        $this->setMany([$key->value => $value], $actor);
    }

    public function setMany(array $values, ?ActorRef $actor = null): void
    {
        $actor ??= ActorRef::system();
        $keys = [];

        DB::transaction(function () use ($values, $actor, &$keys): void {
            foreach ($values as $rawKey => $value) {
                $key = SettingKey::tryFrom((string) $rawKey)
                    ?? throw new InvalidArgumentException("Unknown setting [{$rawKey}].");

                $setting = Setting::query()->firstOrNew(['key' => $key->value]);
                $setting->fill([
                    'value' => $value,
                    'group' => $key->group(),
                    'is_public' => $key->isPublic(),
                ]);
                $setting->updated_by = $actor->type === ActorType::Admin ? $actor->id : null;
                $setting->save();
                $keys[] = $key->value;
            }
        });

        $this->forgetCache();
        $this->events->dispatch(new SettingsUpdated($keys, $actor));
    }

    public function all(): array
    {
        $all = [];
        foreach (SettingKey::cases() as $key) {
            $all[$key->value] = $this->get($key);
        }

        return $all;
    }

    public function public(): array
    {
        $public = [];
        foreach (SettingKey::cases() as $key) {
            if ($key->isPublic()) {
                $public[$key->value] = $this->get($key);
            }
        }

        return $public;
    }

    public function forgetCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        /** @var array<string, mixed> */
        return $this->cache->rememberForever(
            self::CACHE_KEY,
            static fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }
}
