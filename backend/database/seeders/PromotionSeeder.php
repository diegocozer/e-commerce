<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\Product;
use App\Modules\Pricing\Enums\CouponType;
use App\Modules\Pricing\Enums\PromotionDiscountType;
use App\Modules\Pricing\Enums\PromotionScope;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\Promotion;
use Illuminate\Database\Seeder;

/**
 * DATABASE.md §7.7 promotions and coupons.
 * ADR-028: "Semana do Vinil" must NOT affect vinil-adesivo-branco-122m (which
 * must price at R$ 15,90/m), so it targets the other vinyl products instead of
 * the whole `vinis` category.
 */
class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        $promotion = Promotion::query()->updateOrCreate(['name' => 'Semana do Vinil'], [
            'description' => 'Semana do Vinil: 10% de desconto em vinis selecionados',
            'discount_type' => PromotionDiscountType::Percent,
            'value' => 1000,
            'scope' => PromotionScope::Targeted,
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'is_active' => true,
            'priority' => 10,
        ]);
        $promotion->syncTargets('product', Product::query()
            ->whereIn('slug', ['vinil-adesivo-preto-fosco-122m', 'vinil-transparente-100m'])
            ->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $coupons = [
            ['code' => 'BEMVINDO10', 'type' => CouponType::Percent, 'value' => 1000, 'max_discount_cents' => 5000, 'usage_limit_per_customer' => 1,
                'description' => 'Boas-vindas: 10% de desconto (máx. R$ 50)'],
            ['code' => 'FRETEGRATIS', 'type' => CouponType::FreeShipping, 'value' => 0, 'min_order_cents' => 20000, 'usage_limit' => 100,
                'description' => 'Frete grátis em pedidos a partir de R$ 200'],
            ['code' => 'DESC20', 'type' => CouponType::Fixed, 'value' => 2000, 'min_order_cents' => 15000, 'ends_at' => now()->addDays(30),
                'description' => 'R$ 20 de desconto em pedidos a partir de R$ 150'],
        ];

        foreach ($coupons as $coupon) {
            Coupon::query()->updateOrCreate(['code' => $coupon['code']], [
                'min_order_cents' => 0, 'max_discount_cents' => null, 'usage_limit' => null, 'usage_limit_per_customer' => null,
                'starts_at' => null, 'ends_at' => null, 'is_active' => true, ...$coupon,
            ]);
        }
    }
}
