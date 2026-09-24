<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/** coupons.type. */
enum CouponType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case FreeShipping = 'free_shipping';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
