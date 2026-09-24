<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** payment_refunds.requested_by_type. */
enum RefundRequesterType: string
{
    case Admin = 'admin';
    case System = 'system';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
