<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** payment_refunds.status. */
enum PaymentRefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
