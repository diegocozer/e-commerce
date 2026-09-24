<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Enums;

/** shipping_methods.type / orders.shipping_method_type (ADR-011). */
enum ShippingMethodType: string
{
    case Pickup = 'pickup';
    case OwnDelivery = 'own_delivery';
    case TableRate = 'table_rate';
    case Carrier = 'carrier';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Only own_delivery and table_rate methods have shipping_rules (ADR-025). */
    public function usesRules(): bool
    {
        return $this === self::OwnDelivery || $this === self::TableRate;
    }
}
