<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Company;
use App\Modules\Pricing\Models\CustomerPrice;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceTier;
use Illuminate\Database\Seeder;

/** DATABASE.md §7.7 — price tiers, customer price and variant promotional price. */
class PricingSeeder extends Seeder
{
    public function run(): void
    {
        $wholesale = PriceList::query()->where('code', 'wholesale')->value('id');

        $tiers = [
            ['VIN-BR-122-BR', null, '10', 1490], ['VIN-BR-122-BR', null, '50', 1390],
            ['ILH-0-LAT', null, '500', 40], ['ILH-0-LAT', null, '2000', 35],
            ['LON-FL-440-SM', null, '20', 2700],
            ['VIN-BR-122-BR', $wholesale, '1', 1450], ['VIN-BR-122-BR', $wholesale, '50', 1320],
        ];

        foreach ($tiers as [$sku, $listId, $minQuantity, $price]) {
            $variantId = $this->variantId($sku);
            $tier = PriceTier::query()
                ->where('variant_id', $variantId)
                ->where('price_list_id', $listId)
                ->where('min_quantity', $minQuantity)
                ->first() ?? new PriceTier(['variant_id' => $variantId, 'price_list_id' => $listId, 'min_quantity' => $minQuantity]);
            $tier->price_cents = $price;
            $tier->save();
        }

        $companyId = Company::query()->where('cnpj', '11222333000181')->value('id');
        if ($companyId !== null) {
            CustomerPrice::query()->updateOrCreate(
                ['company_id' => $companyId, 'variant_id' => $this->variantId('LON-FL-440-SM')],
                ['customer_id' => null, 'price_cents' => 2590, 'starts_at' => null, 'ends_at' => null],
            );
        }

        ProductVariant::query()->where('sku', 'FIT-DF-19')->firstOrFail()->update([
            'promo_price_cents' => 990,
            'promo_starts_at' => now(),
            'promo_ends_at' => now()->addDays(30),
        ]);
    }

    private function variantId(string $sku): int
    {
        return (int) ProductVariant::query()->where('sku', $sku)->valueOrFail('id');
    }
}
