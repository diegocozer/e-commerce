<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Support;

final class SystemMonotonicClock implements MonotonicClock
{
    public function nowMs(): int
    {
        return intdiv(hrtime(true), 1_000_000);
    }

    public function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
