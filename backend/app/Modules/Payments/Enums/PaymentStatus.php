<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** payments.status (one charge attempt). */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** At most one active payment per order (payments_order_active_unique). */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
