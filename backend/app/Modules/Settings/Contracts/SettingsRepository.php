<?php

declare(strict_types=1);

namespace App\Modules\Settings\Contracts;

use App\Modules\Settings\Enums\SettingKey;
use App\Shared\Domain\ActorRef;

/**
 * Typed, cached access to the `settings` table (DATABASE.md §3.10.3).
 * Missing rows fall back to SettingKey::defaultValue().
 */
interface SettingsRepository
{
    public function get(SettingKey $key): mixed;

    public function string(SettingKey $key): ?string;

    public function int(SettingKey $key): int;

    public function bool(SettingKey $key): bool;

    /** @return array<array-key, mixed> */
    public function array(SettingKey $key): array;

    /** Persists the value, records who changed it, clears the cache and dispatches SettingsUpdated. */
    public function set(SettingKey $key, mixed $value, ?ActorRef $actor = null): void;

    /**
     * Several keys in one transaction (one SettingsUpdated event).
     *
     * @param  array<string, mixed>  $values  key (SettingKey value) => value
     */
    public function setMany(array $values, ?ActorRef $actor = null): void;

    /** @return array<string, mixed> every known key with its effective value */
    public function all(): array;

    /** @return array<string, mixed> only keys flagged as public */
    public function public(): array;

    public function forgetCache(): void;
}
