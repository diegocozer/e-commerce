<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** payment_transactions.type. */
enum PaymentTransactionType: string
{
    case Create = 'create';
    case Approve = 'approve';
    case Fail = 'fail';
    case Refund = 'refund';
    case Expire = 'expire';
    case Cancel = 'cancel';
    case Sync = 'sync';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
