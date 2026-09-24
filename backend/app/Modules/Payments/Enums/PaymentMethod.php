<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** Payment method (payments.method, orders.payment_method). MVP: pix. */
enum PaymentMethod: string
{
    case Pix = 'pix';
    case CreditCard = 'credit_card';
    case Boleto = 'boleto';
    case Invoice = 'invoice';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::CreditCard => 'Cartão de crédito',
            self::Boleto => 'Boleto',
            self::Invoice => 'Faturado',
        };
    }
}
