<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Pricing\Enums\PriceListKind;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Database\Seeder;

/** DATABASE.md §7.3 */
class PriceListSeeder extends Seeder
{
    public function run(): void
    {
        $lists = [
            ['code' => 'retail', 'name' => 'Varejo', 'kind' => PriceListKind::Retail, 'discount_bp' => null, 'is_default' => true],
            ['code' => 'wholesale', 'name' => 'Atacado', 'kind' => PriceListKind::Wholesale, 'discount_bp' => 1000, 'is_default' => false],
            ['code' => 'reseller', 'name' => 'Revendedor', 'kind' => PriceListKind::Reseller, 'discount_bp' => 1500, 'is_default' => false],
        ];

        foreach ($lists as $list) {
            PriceList::query()->updateOrCreate(['code' => $list['code']], [...$list, 'is_active' => true]);
        }
    }
}
