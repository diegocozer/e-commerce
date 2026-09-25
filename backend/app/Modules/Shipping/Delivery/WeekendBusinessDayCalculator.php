<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Delivery;

use App\Modules\Shipping\Contracts\BusinessDayCalculator;

/**
 * MVP business days: weekends only, store timezone, cut-off time (SHIPPING.md §8).
 * Payment on a weekend or after the cut-off starts counting on the next business day.
 */
final class WeekendBusinessDayCalculator implements BusinessDayCalculator
{
    public function __construct(private readonly string $timezone = 'America/Sao_Paulo', private readonly string $cutoff = '14:00') {}

    public function addBusinessDays(\DateTimeImmutable $paidAt, int $days): \DateTimeImmutable
    {
        $local = $paidAt->setTimezone(new \DateTimeZone($this->timezone));
        $day = $local->setTime(0, 0);
        [$h, $m] = array_map('intval', explode(':', $this->cutoff));

        if (self::isWeekend($day) || $local >= $day->setTime($h, $m)) {
            $day = $this->nextBusinessDay($day);
        }
        for ($i = 0; $i < max(0, $days); $i++) {
            $day = $this->nextBusinessDay($day);
        }

        return $day;
    }

    private function nextBusinessDay(\DateTimeImmutable $day): \DateTimeImmutable
    {
        do {
            $day = $day->modify('+1 day');
        } while (self::isWeekend($day));

        return $day;
    }

    private static function isWeekend(\DateTimeImmutable $day): bool
    {
        return (int) $day->format('N') >= 6;
    }
}
