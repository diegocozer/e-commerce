<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Inventory;
use App\Shared\Domain\Quantity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductVariant> */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'SKU-'.fake()->unique()->bothify('???###'),
            'gtin' => null,
            'name' => 'Padrão',
            'attributes' => [],
            'price_cents' => fake()->numberBetween(500, 50000),
            'promo_price_cents' => null,
            'cost_cents' => null,
            'weight_grams' => fake()->numberBetween(10, 5000),
            'package_length_cm' => '30.0',
            'package_width_cm' => '20.0',
            'package_height_cm' => '10.0',
            'is_active' => true,
            'position' => 0,
        ];
    }

    /** Creates the 1:1 inventory row with the given on-hand quantity. */
    public function withStock(string $onHand = '100', string $reserved = '0'): static
    {
        return $this->afterCreating(function (ProductVariant $variant) use ($onHand, $reserved): void {
            $inventory = new Inventory(['variant_id' => $variant->id]);
            $inventory->on_hand = Quantity::fromString($onHand);
            $inventory->reserved = Quantity::fromString($reserved);
            $inventory->save();
        });
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
