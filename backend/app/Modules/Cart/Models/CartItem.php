<?php

declare(strict_types=1);

namespace App\Modules\Cart\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Shared\Casts\QuantityCast;
use App\Shared\Domain\Dimensions;
use App\Shared\Domain\Quantity;
use Database\Factories\Cart\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer's choice only (ADR-007): variant + quantity, or width/height/pieces
 * for SQUARE_METER (quantity NULL, ADR-019). Prices are always recalculated.
 *
 * @property int $id
 * @property int $cart_id
 * @property int $variant_id
 * @property Quantity|null $quantity
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property int|null $pieces
 */
class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $table = 'cart_items';

    protected $fillable = ['variant_id', 'quantity', 'width_mm', 'height_mm', 'pieces'];

    protected function casts(): array
    {
        return [
            'quantity' => QuantityCast::class,
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'pieces' => 'integer',
        ];
    }

    public function dimensions(): ?Dimensions
    {
        return $this->width_mm !== null && $this->height_mm !== null
            ? new Dimensions($this->width_mm, $this->height_mm)
            : null;
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    protected static function newFactory(): CartItemFactory
    {
        return CartItemFactory::new();
    }
}
