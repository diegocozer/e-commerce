<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Sale unit of a product (ADR-004, DB-05). Lives in the shared kernel because
 * Catalog, Cart, Orders and Shipping all depend on it (order_items keep a
 * snapshot), and Orders/Shipping must not depend on Catalog.
 */
enum SaleUnit: string
{
    case Unit = 'UNIT';
    case LinearMeter = 'LINEAR_METER';
    case SquareMeter = 'SQUARE_METER';
    case Roll = 'ROLL';
    case Kg = 'KG';
    case Box = 'BOX';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unidade',
            self::LinearMeter => 'Metro linear',
            self::SquareMeter => 'Metro quadrado',
            self::Roll => 'Rolo',
            self::Kg => 'Quilograma',
            self::Box => 'Caixa',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::Unit => 'un',
            self::LinearMeter => 'm',
            self::SquareMeter => 'm²',
            self::Roll => 'rolo',
            self::Kg => 'kg',
            self::Box => 'cx',
        };
    }

    /** Whether the customer's quantity input may be fractional (quantity_step). */
    public function allowsFraction(): bool
    {
        return match ($this) {
            self::LinearMeter, self::Kg => true,
            default => false,
        };
    }

    /** SQUARE_METER: input is width × height × pieces; cart_items.quantity is NULL (ADR-019). */
    public function usesDimensions(): bool
    {
        return $this === self::SquareMeter;
    }

    /** "/m", "/un" … (price suffix, BUSINESS_RULES.md §4.2.1). */
    public function priceSuffix(): string
    {
        return '/'.$this->abbreviation();
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
