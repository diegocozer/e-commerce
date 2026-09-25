<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;

/** Reads Catalog's InvalidSaleQuantity for the `invalid_quantity` line warning. */
final class InvalidQuantityDetails
{
    /** @return array{field: string, message: string, suggestions: list<int|float>} */
    public static function from(InvalidSaleQuantity $e): array
    {
        return ['field' => $e->field, 'message' => $e->message(), 'suggestions' => array_values($e->suggestions ?? [])];
    }
}
