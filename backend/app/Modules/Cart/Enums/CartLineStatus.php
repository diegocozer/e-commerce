<?php

declare(strict_types=1);

namespace App\Modules\Cart\Enums;

/** API.md §2.5 CartItemStatus. Anything but Ok blocks the checkout. */
enum CartLineStatus: string
{
    case Ok = 'ok';
    case Unavailable = 'unavailable';
    case InsufficientStock = 'insufficient_stock';
    case InvalidQuantity = 'invalid_quantity';

    /** Line has a price and counts in the subtotal. */
    public function isPriceable(): bool
    {
        return $this === self::Ok || $this === self::InsufficientStock;
    }
}
