<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** Zone location kind; the value is the specificity (higher = more specific, SHIPPING.md §4.2). */
enum ZoneLocationType: int
{
    case Global = 0;
    case State = 1;
    case City = 2;
    case PostalRange = 3;

    public function label(): string
    {
        return match ($this) {
            self::Global => 'global',
            self::State => 'state',
            self::City => 'city',
            self::PostalRange => 'postal_range',
        };
    }
}
