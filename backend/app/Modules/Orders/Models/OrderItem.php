<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\Promotion;
use App\Shared\Casts\QuantityCast;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Database\Factories\Orders\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable order line snapshot (DATABASE.md §3.6.3). `billable_quantity`
 * (billed, with minimum area) ≠ `stock_quantity` (stock movement) for
 * SQUARE_METER (ADR-019). Money columns are set explicitly (not fillable).
 * `variant_id`/`product_id` reference Catalog, which Orders does not depend on.
 *
 * @property int $id
 * @property int $order_id
 * @property int $variant_id
 * @property int $product_id
 * @property string $sku
 * @property SaleUnit $sale_unit
 * @property Quantity|null $quantity
 * @property Quantity $billable_quantity
 * @property Quantity $stock_quantity
 * @property int $unit_price_cents
 * @property PriceSource $price_source
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $total_cents
 */
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'order_items';

    protected $fillable = [
        'variant_id', 'product_id', 'product_name', 'variant_name', 'sku', 'sale_unit', 'quantity', 'width_mm',
        'height_mm', 'pieces', 'billable_quantity', 'stock_quantity', 'price_source', 'price_list_id',
        'promotion_id', 'weight_grams',
    ];

    protected function casts(): array
    {
        return [
            'sale_unit' => SaleUnit::class,
            'quantity' => QuantityCast::class,
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'pieces' => 'integer',
            'billable_quantity' => QuantityCast::class,
            'stock_quantity' => QuantityCast::class,
            'base_unit_price_cents' => 'integer',
            'unit_price_cents' => 'integer',
            'price_source' => PriceSource::class,
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'weight_grams' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<Promotion, $this> */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }
}
