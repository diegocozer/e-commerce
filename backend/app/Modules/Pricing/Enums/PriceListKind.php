<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/** price_lists.kind. */
enum PriceListKind: string
{
    case Retail = 'retail';
    case Wholesale = 'wholesale';
    case Reseller = 'reseller';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
