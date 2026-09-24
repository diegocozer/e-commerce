<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/** inventory_movements.type (ADR-008). */
enum InventoryMovementType: string
{
    case In = 'in';
    case Out = 'out';
    case Reserve = 'reserve';
    case Release = 'release';
    case Return = 'return';
    case Adjust = 'adjust';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
