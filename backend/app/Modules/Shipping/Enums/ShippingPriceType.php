<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** shipping_rules.price_type (ADR-025). */
enum ShippingPriceType: string
{
    case Fixed = 'fixed';
    case PerKg = 'per_kg';
    case FixedPlusPerKg = 'fixed_plus_per_kg';
    case PercentageOfSubtotal = 'percentage_of_subtotal';
    case Free = 'free';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
