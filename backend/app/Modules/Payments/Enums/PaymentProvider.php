<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** payments.provider / webhook_events.provider (ADR-010). */
enum PaymentProvider: string
{
    case Sandbox = 'sandbox';
    case MercadoPago = 'mercadopago';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
