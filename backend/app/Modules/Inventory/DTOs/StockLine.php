<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

use App\Shared\Domain\Quantity;

/** One variant quantity of a reservation, in the stock unit (m² for SQUARE_METER, without minimum area). */
final readonly class StockLine
{
    public function __construct(
        public int $variantId,
        public Quantity $quantity,
    ) {}
}
