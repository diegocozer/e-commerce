<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Config;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Shipping\Domain\Logistics\Dimension;
use App\Modules\Shipping\DTOs\CarrierConfig;
use App\Modules\Shipping\DTOs\PickupAddress;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingRule;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Loads the shipping configuration into an immutable snapshot cached under
 * `shipping:config:v{version}` (TTL 10 min). Every write to a shipping table
 * bumps `shipping:config:version` (model listeners in the provider).
 */
final class ShippingConfigRepository
{
    public const string VERSION_KEY = 'shipping:config:version';

    public function __construct(private readonly Cache $cache, private readonly SettingsRepository $settings) {}

    public function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 1);
    }

    public function bumpVersion(): int
    {
        $next = $this->version() + 1;
        $this->cache->forever(self::VERSION_KEY, $next);

        return $next;
    }

    public function snapshot(): ShippingConfigSnapshot
    {
        return $this->cache->remember(
            'shipping:config:v'.$this->version(),
            (int) config('shipping.config_cache_ttl_seconds', 600),
            fn (): ShippingConfigSnapshot => $this->load(),
        );
    }

    public function load(): ShippingConfigSnapshot
    {
        $methods = ShippingMethod::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get()
            ->map(fn (ShippingMethod $m): MethodConfig => self::method($m))->values()->all();

        $rules = [];
        foreach (ShippingRule::query()->where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            $rules[$rule->method_id][] = self::rule($rule);
        }

        $zones = [];
        foreach (ShippingZone::query()->where('is_active', true)->with(['postalRanges', 'cities', 'states'])->orderBy('id')->get() as $zone) {
            $zones[$zone->id] = new ZoneConfig(
                $zone->id,
                $zone->name,
                $zone->postalRanges->map(fn ($r): array => [(string) $r->start_postal_code, (string) $r->end_postal_code])->values()->all(),
                $zone->cities->pluck('city_ibge_code')->map(fn ($c): string => (string) $c)->values()->all(),
                $zone->states->pluck('state')->map(fn ($s): string => (string) $s)->values()->all(),
            );
        }

        $carriers = [];
        foreach (ShippingCarrier::query()->orderBy('id')->get() as $carrier) {
            $carriers[$carrier->id] = $this->carrier($carrier, withCredentials: false); // secrets never cached
        }

        return new ShippingConfigSnapshot($methods, $rules, $zones, $carriers, (int) config('shipping.default_cubic_divisor', 6000));
    }

    public function carrier(ShippingCarrier $carrier, bool $withCredentials = true): CarrierConfig
    {
        $settings = $carrier->settings ?? [];
        $store = $this->settings->array(SettingKey::StoreAddress);
        $timeout = (int) ($settings['timeout_ms'] ?? config('shipping.carrier_default_timeout_ms', 5000));

        return new CarrierConfig(
            carrierId: $carrier->id,
            code: $carrier->code,
            driver: $carrier->driver,
            name: $carrier->name,
            settings: $settings,
            credentials: $withCredentials ? ($carrier->credentials ?? []) : [],
            timeoutMs: max(500, min(15000, $timeout)),
            cubicDivisor: max(1, (int) ($settings['cubic_divisor'] ?? config('shipping.default_cubic_divisor', 6000))),
            originPostalCode: (string) ($settings['origin_postal_code'] ?? $store['postal_code'] ?? '89010001'),
            isActive: $carrier->is_active,
        );
    }

    /** Carrier configuration with decrypted credentials (loaded on demand, never cached). */
    public function carrierWithCredentials(int $carrierId): ?CarrierConfig
    {
        $carrier = ShippingCarrier::query()->find($carrierId);

        return $carrier === null ? null : $this->carrier($carrier);
    }

    public static function method(ShippingMethod $m): MethodConfig
    {
        $pickup = null;
        if ($m->pickup_street !== null && $m->pickup_city !== null) {
            $pickup = new PickupAddress(
                $m->pickup_street, $m->pickup_number, $m->pickup_complement, $m->pickup_district, $m->pickup_city,
                (string) $m->pickup_state, (string) $m->pickup_postal_code, $m->pickup_opening_hours, $m->pickup_instructions,
            );
        }

        return new MethodConfig(
            $m->id, $m->code, $m->name, $m->description, $m->type, $m->carrier_id, $m->carrier_service_code,
            (int) $m->delivery_days_min, (int) $m->delivery_days_max, (int) $m->handling_days, $m->weight_basis,
            $m->cubic_divisor, $m->accepts_free_shipping_coupon, (int) $m->position, $pickup,
        );
    }

    public static function rule(ShippingRule $r): RuleConfig
    {
        return new RuleConfig(
            $r->id, $r->method_id, $r->zone_id, $r->name, $r->priority,
            $r->min_weight_grams, $r->max_weight_grams, $r->min_subtotal_cents, $r->max_subtotal_cents,
            $r->min_volume_cm3, $r->max_volume_cm3,
            $r->max_package_length_cm === null ? null : Dimension::cmToMm((string) $r->max_package_length_cm),
            $r->price_type, (int) $r->price_cents, (int) $r->per_kg_cents, (int) $r->percentage_bp,
            $r->min_price_cents, $r->max_price_cents, $r->delivery_days_min, $r->delivery_days_max, $r->is_active,
            $r->valid_from, $r->valid_until,
        );
    }
}
