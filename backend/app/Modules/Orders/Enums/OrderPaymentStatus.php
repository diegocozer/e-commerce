<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** orders.payment_status (ADR-008). */
enum OrderPaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case Expired = 'expired';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
