<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A manual movement (`in` or `adjust`) changed on_hand. */
final class StockAdjusted
{
    use Dispatchable;

    public function __construct(
        public readonly int $variantId,
        public readonly int $movementId,
        public readonly string $type,
        public readonly string $onHandAfter,
        public readonly ?int $adminUserId,
    ) {}
}
