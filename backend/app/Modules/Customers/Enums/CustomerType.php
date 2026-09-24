<?php

declare(strict_types=1);

namespace App\Modules\Customers\Enums;

/** customers.type / orders.customer_type (PF × PJ). */
enum CustomerType: string
{
    case Individual = 'individual';
    case Company = 'company';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
