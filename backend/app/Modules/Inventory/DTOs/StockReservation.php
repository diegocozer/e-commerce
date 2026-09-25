<?php

declare(strict_types=1);

namespace App\Modules\Inventory\DTOs;

/** Stock operation of a reference (e.g. an order). Lines of the same variant are summed. */
final readonly class StockReservation
{
    /**
     * @param  string  $referenceType  morph alias, e.g. 'order'
     * @param  list<StockLine>  $lines
     */
    public function __construct(
        public string $referenceType,
        public int $referenceId,
        public array $lines,
    ) {}
}
