<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Resources\Admin;

use App\Modules\Shipping\Actions\ShippingAdminSupport;
use App\Modules\Shipping\Enums\ShippingPriceType;
use App\Modules\Shipping\Models\ShippingRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §2.15 ShippingRule. */
final class RuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShippingRule $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'method_id' => $r->method_id,
            'zone_id' => $r->zone_id,
            'name' => $r->name,
            'priority' => $r->priority,
            'min_weight_grams' => $r->min_weight_grams,
            'max_weight_grams' => $r->max_weight_grams,
            'min_subtotal_cents' => $r->min_subtotal_cents,
            'max_subtotal_cents' => $r->max_subtotal_cents,
            'min_volume_cm3' => $r->min_volume_cm3,
            'max_volume_cm3' => $r->max_volume_cm3,
            'max_package_length_cm' => $r->max_package_length_cm === null ? null : (float) $r->max_package_length_cm,
            'price_type' => $r->price_type->value,
            'price_cents' => $r->price_cents,
            'per_kg_cents' => $r->per_kg_cents,
            'percentage_bp' => $r->percentage_bp,
            'min_price_cents' => $r->min_price_cents,
            'max_price_cents' => $r->max_price_cents,
            'delivery_days_min' => $r->delivery_days_min,
            'delivery_days_max' => $r->delivery_days_max,
            'valid_from' => ShippingAdminSupport::iso($r->valid_from),
            'valid_until' => ShippingAdminSupport::iso($r->valid_until),
            'is_active' => $r->is_active,
            'summary' => self::summary($r),
            'created_at' => ShippingAdminSupport::iso($r->created_at),
            'updated_at' => ShippingAdminSupport::iso($r->updated_at),
            'deleted_at' => ShippingAdminSupport::iso($r->deleted_at),
        ];
    }

    /** "Blumenau · ≤ 10 kg → R$ 20,00" */
    public static function summary(ShippingRule $r): string
    {
        $parts = [$r->zone?->name ?? 'Qualquer destino coberto'];
        if ($r->min_weight_grams !== null) {
            $parts[] = '≥ '.self::kg($r->min_weight_grams);
        }
        if ($r->max_weight_grams !== null) {
            $parts[] = '≤ '.self::kg($r->max_weight_grams);
        }
        if ($r->min_subtotal_cents !== null) {
            $parts[] = 'pedido ≥ '.self::money($r->min_subtotal_cents);
        }
        $price = match ($r->price_type) {
            ShippingPriceType::Fixed => self::money($r->price_cents),
            ShippingPriceType::PerKg => self::money($r->per_kg_cents).'/kg',
            ShippingPriceType::FixedPlusPerKg => self::money($r->price_cents).' + '.self::money($r->per_kg_cents).'/kg',
            ShippingPriceType::PercentageOfSubtotal => number_format($r->percentage_bp / 100, 2, ',', '.').'% do subtotal',
            ShippingPriceType::Free => 'Grátis',
        };

        return implode(' · ', $parts).' → '.$price;
    }

    private static function kg(int $grams): string
    {
        return $grams % 1000 === 0 ? intdiv($grams, 1000).' kg' : number_format($grams / 1000, 3, ',', '.').' kg';
    }

    private static function money(int $cents): string
    {
        return 'R$ '.number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
