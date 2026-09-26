<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\Domain\Config\ShippingConfigSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shipping\Concerns\BuildsShippingFixture;
use Tests\TestCase;

/**
 * Real cache stores (database/redis) unserialize with `cache.serializable_classes`.
 * The shipping config snapshot must survive that round trip (integration bug:
 * __PHP_Incomplete_Class → 500 on every quote after the first) — ADR-035.
 */
final class ShippingConfigCacheSerializationTest extends TestCase
{
    use BuildsShippingFixture;
    use RefreshDatabase;

    public function test_snapshot_round_trips_with_the_cache_allow_list(): void
    {
        $this->seedShippingFixture();
        $snapshot = $this->app->make(ShippingConfigRepository::class)->load();

        $restored = unserialize(serialize($snapshot), ['allowed_classes' => config('cache.serializable_classes')]);

        self::assertInstanceOf(ShippingConfigSnapshot::class, $restored);
        self::assertEquals($snapshot, $restored);
    }

    public function test_snapshot_is_served_from_a_serializing_store(): void
    {
        $this->seedShippingFixture();
        config(['cache.stores.array.serialize' => true]);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        $this->app->forgetInstance(ShippingConfigRepository::class);

        $repo = $this->app->make(ShippingConfigRepository::class);
        $first = $repo->snapshot();
        $second = $this->app->make(ShippingConfigRepository::class)->snapshot();

        self::assertInstanceOf(ShippingConfigSnapshot::class, $second);
        self::assertEquals($first, $second);
    }
}
