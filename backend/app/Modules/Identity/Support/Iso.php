<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

final class Iso
{
    public static function dt(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
