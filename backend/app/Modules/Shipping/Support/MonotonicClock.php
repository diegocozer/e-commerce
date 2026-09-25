<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Support;

/** Monotonic milliseconds for the carrier time budget (fakeable in tests). */
interface MonotonicClock
{
    public function nowMs(): int;

    public function sleepMs(int $ms): void;
}
