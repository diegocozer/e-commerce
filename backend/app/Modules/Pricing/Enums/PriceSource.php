<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Enums;

/** Origin of a resolved unit price (order_items.price_source, ADR-005). */
enum PriceSource: string
{
    case Base = 'base';
    case Tier = 'tier';
    case PriceList = 'price_list';
    case VariantPromo = 'variant_promo';
    case Promotion = 'promotion';
    case CustomerPrice = 'customer_price';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Preço',
            self::Tier => 'Preço por quantidade',
            self::PriceList => 'Preço de tabela',
            self::VariantPromo => 'Preço promocional',
            self::Promotion => 'Promoção',
            self::CustomerPrice => 'Preço especial',
        };
    }
}
