<?php

declare(strict_types=1);

namespace App\Modules\Customers\DTOs;

final readonly class CustomerPricingProfile
{
    /** @param int|null $priceListId effective list: customer → company → default (null when none) */
    public function __construct(
        public int $customerId,
        public ?int $companyId,
        public ?int $priceListId,
    ) {}
}
