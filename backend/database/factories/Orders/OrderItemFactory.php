<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pricing\Enums\PriceSource;
use App\Shared\Domain\SaleUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Default: UNIT line, 2 units at the variant price. @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'variant_id' => ProductVariant::factory(),
            'product_id' => fn (array $a): int => ProductVariant::query()->findOrFail($a['variant_id'])->product_id,
            'product_name' => fake()->words(3, true),
            'variant_name' => 'Padrão',
            'sku' => fn (array $a): string => ProductVariant::query()->findOrFail($a['variant_id'])->sku,
            'sale_unit' => SaleUnit::Unit,
            'quantity' => '2',
            'billable_quantity' => '2',
            'stock_quantity' => '2',
            'base_unit_price_cents' => 1000,
            'unit_price_cents' => 1000,
            'price_source' => PriceSource::Base,
            'subtotal_cents' => 2000,
            'discount_cents' => 0,
            'total_cents' => 2000,
            'weight_grams' => 200,
        ];
    }
}
