<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** orders.status (ADR-008 state machine lives in the Orders module). */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case ReadyForPickup = 'ready_for_pickup';
    case PickedUp = 'picked_up';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Aguardando pagamento',
            self::Paid => 'Pago',
            self::Processing => 'Em separação',
            self::Shipped => 'Enviado',
            self::Delivered => 'Entregue',
            self::ReadyForPickup => 'Pronto para retirada',
            self::PickedUp => 'Retirado',
            self::Cancelled => 'Cancelado',
        };
    }
}
