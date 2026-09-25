<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Shared\Domain\SaleUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Default: active UNIT product. States for every sale unit keep the product
 * CHECK constraints satisfied (DATABASE.md §3.1.3).
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(3, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'short_description' => fake()->sentence(),
            'description' => '<p>'.fake()->paragraph().'</p>',
            'sale_unit' => SaleUnit::Unit,
            'brand_id' => Brand::factory(),
            'primary_category_id' => Category::factory(),
            'min_quantity' => '1',
            'max_quantity' => null,
            'quantity_step' => '1',
            'is_active' => true,
            'is_featured' => false,
            'pickup_only' => false,
        ];
    }

    /** Keeps product_categories in sync with the primary category. */
    public function configure(): static
    {
        return $this->afterCreating(function (Product $product): void {
            $product->categories()->syncWithoutDetaching([$product->primary_category_id => ['position' => 0]]);
        });
    }

    public function linearMeter(int $fixedWidthMm = 1220, string $step = '0.100'): static
    {
        return $this->state([
            'sale_unit' => SaleUnit::LinearMeter,
            'fixed_width_mm' => $fixedWidthMm,
            'min_quantity' => '1',
            'quantity_step' => $step,
        ]);
    }

    public function squareMeter(?string $minBillableArea = '0.500'): static
    {
        return $this->state([
            'sale_unit' => SaleUnit::SquareMeter,
            'min_billable_area_m2' => $minBillableArea,
            'min_width_mm' => 300,
            'max_width_mm' => 3200,
            'min_height_mm' => 300,
            'max_height_mm' => 50000,
        ]);
    }

    public function roll(): static
    {
        return $this->state(['sale_unit' => SaleUnit::Roll]);
    }

    public function kg(): static
    {
        return $this->state(['sale_unit' => SaleUnit::Kg, 'min_quantity' => '0.500', 'quantity_step' => '0.500']);
    }

    public function box(): static
    {
        return $this->state(['sale_unit' => SaleUnit::Box]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
