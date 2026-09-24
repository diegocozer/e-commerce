<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/** promotions.discount_type (percent = basis points; fixed = cents per sale unit). */
enum PromotionDiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
