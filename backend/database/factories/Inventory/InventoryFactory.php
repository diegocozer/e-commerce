<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Inventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inventory> */
class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'on_hand' => '100.000',
            'reserved' => '0.000',
            'low_stock_threshold' => null,
        ];
    }
}
