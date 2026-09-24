<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/** promotions.scope. */
enum PromotionScope: string
{
    case All = 'all';
    case Targeted = 'targeted';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
