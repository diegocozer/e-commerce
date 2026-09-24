<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** orders.cancel_reason_code. */
enum CancelReasonCode: string
{
    case PaymentExpired = 'payment_expired';
    case Customer = 'customer';
    case Admin = 'admin';
    case PaymentFailed = 'payment_failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
