<?php

declare(strict_types=1);

namespace Database\Factories\Cart;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Default: quantity line (non SQUARE_METER). @extends Factory<CartItem> */
class CartItemFactory extends Factory
{
    protected $model = CartItem::class;

    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'variant_id' => ProductVariant::factory(),
            'quantity' => '2',
            'width_mm' => null,
            'height_mm' => null,
            'pieces' => null,
        ];
    }

    public function dimensions(int $widthMm = 1200, int $heightMm = 2500, int $pieces = 1): static
    {
        return $this->state(['quantity' => null, 'width_mm' => $widthMm, 'height_mm' => $heightMm, 'pieces' => $pieces]);
    }
}
