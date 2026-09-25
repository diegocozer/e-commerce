<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** ISO-8601 UTC with "Z" (API.md §1.5). */
final class Iso
{
    public static function dt(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
