<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** shipping_methods.weight_basis: real or chargeable (cubed) weight. */
enum WeightBasis: string
{
    case Real = 'real';
    case Chargeable = 'chargeable';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
