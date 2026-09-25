<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

final readonly class ZoneMatchSet
{
    /** @param  array<int, ZoneMatch>  $byZoneId */
    public function __construct(public array $byZoneId) {}

    public function get(int $zoneId): ?ZoneMatch
    {
        return $this->byZoneId[$zoneId] ?? null;
    }

    public function has(int $zoneId): bool
    {
        return isset($this->byZoneId[$zoneId]);
    }
}
