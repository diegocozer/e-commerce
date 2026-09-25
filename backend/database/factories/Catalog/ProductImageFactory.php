<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductImage> */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'disk' => 'public',
            'path' => 'products/placeholder.webp',
            'alt' => fake()->sentence(3),
            'width_px' => 800,
            'height_px' => 800,
            'position' => 0,
        ];
    }
}
