<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Available fell to or below the threshold (RN-EST-010). One per variant until it recovers. */
final class StockLow
{
    use Dispatchable;

    public function __construct(
        public readonly int $variantId,
        public readonly string $available,
        public readonly string $threshold,
    ) {}
}
