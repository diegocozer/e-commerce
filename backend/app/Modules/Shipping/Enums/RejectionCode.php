<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** Why a shipping rule did not match (SHIPPING.md §4.7). */
enum RejectionCode: string
{
    case Inactive = 'inactive';
    case NotYetValid = 'not_yet_valid';
    case Expired = 'expired';
    case WeightBelowMin = 'weight_below_min';
    case WeightAboveMax = 'weight_above_max';
    case SubtotalBelowMin = 'subtotal_below_min';
    case SubtotalAboveMax = 'subtotal_above_max';
    case VolumeBelowMin = 'volume_below_min';
    case VolumeAboveMax = 'volume_above_max';
    case LengthAboveMax = 'length_above_max';

    public function isTemporal(): bool
    {
        return $this === self::Inactive || $this === self::NotYetValid || $this === self::Expired;
    }
}
