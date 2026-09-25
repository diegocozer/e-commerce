<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Carriers;

use App\Modules\Shipping\Contracts\ShippingCarrierInterface;
use App\Modules\Shipping\DTOs\CarrierConfig;
use App\Modules\Shipping\Exceptions\CarrierNotRegisteredException;
use Closure;

/** Carrier drivers by name (SHIPPING.md §4.5). Instances are memoized per carrier id. */
final class CarrierRegistry
{
    /** @var array<string, array{factory: Closure(CarrierConfig): ShippingCarrierInterface, name: string, settings_schema: array<string, string>}> */
    private array $drivers = [];

    /** @var array<string, ShippingCarrierInterface> */
    private array $instances = [];

    /**
     * @param  Closure(CarrierConfig): ShippingCarrierInterface  $factory
     * @param  array<string, 'string'|'integer'|'boolean'>  $settingsSchema  driver-specific settings keys
     */
    public function register(string $driver, Closure $factory, ?string $name = null, array $settingsSchema = []): void
    {
        $this->drivers[$driver] = ['factory' => $factory, 'name' => $name ?? $driver, 'settings_schema' => $settingsSchema];
        foreach (array_keys($this->instances) as $key) {
            if (str_starts_with($key, $driver.'#')) {
                unset($this->instances[$key]);
            }
        }
    }

    public function has(string $driver): bool
    {
        return isset($this->drivers[$driver]);
    }

    /** @throws CarrierNotRegisteredException */
    public function make(CarrierConfig $config): ShippingCarrierInterface
    {
        if (! $this->has($config->driver)) {
            throw new CarrierNotRegisteredException("Carrier driver [{$config->driver}] is not registered.");
        }

        $key = $config->driver.'#'.$config->carrierId.'#'.md5(serialize([$config->settings, $config->timeoutMs]));

        return $this->instances[$key] ??= ($this->drivers[$config->driver]['factory'])($config);
    }

    /** @return list<array{driver: string, name: string, settings_schema: array<string, string>}> */
    public function drivers(): array
    {
        $list = [];
        foreach ($this->drivers as $driver => $meta) {
            $list[] = ['driver' => $driver, 'name' => $meta['name'], 'settings_schema' => $meta['settings_schema']];
        }

        return $list;
    }
}
