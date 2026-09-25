<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Domain\Config\ZoneConfig;
use App\Modules\Shipping\DTOs\Destination;
use App\Modules\Shipping\Enums\ZoneLocationType;

/**
 * A zone matches when ANY of its locations matches; its specificity is the
 * highest among matching locations (SHIPPING.md §4.6). City locations need a
 * resolved destination; ranges and states work with the CEP fallback.
 */
final class ZoneMatcher
{
    /** @param  iterable<ZoneConfig>  $zones */
    public function match(Destination $destination, iterable $zones): ZoneMatchSet
    {
        $matches = [];
        foreach ($zones as $zone) {
            $match = $this->matchZone($destination, $zone);
            if ($match !== null) {
                $matches[$zone->id] = $match;
            }
        }

        return new ZoneMatchSet($matches);
    }

    public function matchZone(Destination $destination, ZoneConfig $zone): ?ZoneMatch
    {
        $cep = $destination->postalCode;
        foreach ($zone->postalRanges as [$start, $end]) {
            if (strcmp($start, $cep) <= 0 && strcmp($cep, $end) <= 0) {
                return new ZoneMatch($zone->id, ZoneLocationType::PostalRange, "postal_range:{$start}-{$end}");
            }
        }

        if ($destination->resolved && $destination->cityIbgeCode !== null && in_array($destination->cityIbgeCode, $zone->cityIbgeCodes, true)) {
            return new ZoneMatch($zone->id, ZoneLocationType::City, 'city:'.$destination->cityIbgeCode);
        }

        if ($destination->state !== null && in_array($destination->state, $zone->states, true)) {
            return new ZoneMatch($zone->id, ZoneLocationType::State, 'state:'.$destination->state);
        }

        return null;
    }
}
