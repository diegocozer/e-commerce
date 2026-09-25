<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

/** Estimated delivery date after payment (SHIPPING.md §8). Used by Orders. */
interface BusinessDayCalculator
{
    /** Adds N business days from the payment instant, honouring the cut-off time. Returns a date (00:00, store timezone). */
    public function addBusinessDays(\DateTimeImmutable $paidAt, int $days): \DateTimeImmutable;
}
