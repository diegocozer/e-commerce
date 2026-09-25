<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

/** Customer of the quote (SHIPPING.md §2.5). No rule uses it in the MVP. */
final readonly class ShippingCustomer
{
    public function __construct(
        public int $id,
        public string $type,      // customers.type: individual | company
        public ?int $companyId = null,
    ) {}
}
