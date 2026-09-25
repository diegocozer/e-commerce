<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

use App\Modules\Catalog\DTOs\BillableQuantity;
use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;

interface SaleQuantityResolver
{
    /**
     * Validates min/max/step, fixed width vs ranges, heights and pieces
     * (ADR-004/019) and computes billable/stock quantities (minimum billable
     * area per piece).
     *
     * @throws InvalidSaleQuantity (422 with field and details.suggestions when off-step)
     */
    public function resolve(VariantData $variant, SaleInput $input): BillableQuantity;
}
