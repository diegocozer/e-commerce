<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Enums\ZoneLocationType;

final readonly class ZoneMatch
{
    /** @param  string  $matchedBy  "postal_range:89010000-89012999" | "city:4202404" | "state:SC" */
    public function __construct(public int $zoneId, public ZoneLocationType $specificity, public string $matchedBy) {}
}
