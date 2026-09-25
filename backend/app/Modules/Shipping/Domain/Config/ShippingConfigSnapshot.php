<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Config;

use App\Modules\Shipping\DTOs\CarrierConfig;

/** Everything the engine needs, loaded once and cached (SHIPPING.md §6.6). */
final readonly class ShippingConfigSnapshot
{
    /**
     * @param  list<MethodConfig>  $methods  active, ordered by (position, id)
     * @param  array<int, list<RuleConfig>>  $rulesByMethod  active rules
     * @param  array<int, ZoneConfig>  $zones  active zones by id
     * @param  array<int, CarrierConfig>  $carriers  all carriers by id (inactive included)
     */
    public function __construct(
        public array $methods,
        public array $rulesByMethod,
        public array $zones,
        public array $carriers,
        public int $defaultCubicDivisor,
    ) {}

    /** @return list<RuleConfig> */
    public function rulesOf(int $methodId): array
    {
        return $this->rulesByMethod[$methodId] ?? [];
    }

    public function method(int $id): ?MethodConfig
    {
        foreach ($this->methods as $method) {
            if ($method->id === $id) {
                return $method;
            }
        }

        return null;
    }
}
