<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Support;

/** Virtual clock: sleeping only advances time (tests / FakeCarrier delays). */
final class FakeMonotonicClock implements MonotonicClock
{
    public function __construct(private int $now = 0) {}

    public function nowMs(): int
    {
        return $this->now;
    }

    public function sleepMs(int $ms): void
    {
        $this->now += max(0, $ms);
    }
}
