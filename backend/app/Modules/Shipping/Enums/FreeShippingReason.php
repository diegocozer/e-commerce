<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** Why a shipping option is free (SHIPPING.md §3). */
enum FreeShippingReason: string
{
    case Rule = 'rule';
    case Coupon = 'coupon';
}
