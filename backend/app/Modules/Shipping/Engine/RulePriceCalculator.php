<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Domain\Config\RuleConfig;
use App\Modules\Shipping\Enums\ShippingPriceType;

/** Rule price in cents (SHIPPING.md §5.2). per_kg charges each started kg, minimum 1 kg. */
final class RulePriceCalculator
{
    public function price(RuleConfig $rule, int $effectiveWeightGrams, int $subtotalCents): int
    {
        return $this->breakdown($rule, $effectiveWeightGrams, $subtotalCents)['final_cents'];
    }

    public static function startedKg(int $grams): int
    {
        return max(1, intdiv($grams + 999, 1000));
    }

    /** @return array{price_type: string, price_cents: int, per_kg_cents: int, kg: int|null, percentage_bp: int, min_price_cents: int|null, max_price_cents: int|null, base_cents: int, final_cents: int} */
    public function breakdown(RuleConfig $rule, int $effectiveWeightGrams, int $subtotalCents): array
    {
        $kg = null;
        $base = match ($rule->priceType) {
            ShippingPriceType::Fixed => $rule->priceCents,
            ShippingPriceType::PerKg => ($kg = self::startedKg($effectiveWeightGrams)) * $rule->perKgCents,
            ShippingPriceType::FixedPlusPerKg => $rule->priceCents + ($kg = self::startedKg($effectiveWeightGrams)) * $rule->perKgCents,
            ShippingPriceType::PercentageOfSubtotal => intdiv($subtotalCents * $rule->percentageBp + 5000, 10000),
            ShippingPriceType::Free => 0,
        };

        $final = $base;
        if ($rule->priceType !== ShippingPriceType::Free) {
            $final = max($final, $rule->minPriceCents ?? 0);
            if ($rule->maxPriceCents !== null) {
                $final = min($final, $rule->maxPriceCents);
            }
        }

        return [
            'price_type' => $rule->priceType->value,
            'price_cents' => $rule->priceCents,
            'per_kg_cents' => $rule->perKgCents,
            'kg' => $kg,
            'percentage_bp' => $rule->percentageBp,
            'min_price_cents' => $rule->minPriceCents,
            'max_price_cents' => $rule->maxPriceCents,
            'base_cents' => $base,
            'final_cents' => $final,
        ];
    }
}
