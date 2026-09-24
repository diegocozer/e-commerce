<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Shared\Casts\QuantityCast;
use App\Shared\Domain\Quantity;
use Database\Factories\Inventory\InventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock balance of a variant (1:1, DATABASE.md §3.3.1). `on_hand` and
 * `reserved` are changed ONLY by the Inventory service, under
 * SELECT ... FOR UPDATE, together with an inventory_movements row (DB-09),
 * hence they are not fillable.
 *
 * @property int $id
 * @property int $variant_id
 * @property Quantity $on_hand
 * @property Quantity $reserved
 * @property Quantity|null $low_stock_threshold
 */
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    protected $table = 'inventory';

    protected $fillable = ['variant_id', 'low_stock_threshold'];

    protected function casts(): array
    {
        return [
            'on_hand' => QuantityCast::class,
            'reserved' => QuantityCast::class,
            'low_stock_threshold' => QuantityCast::class,
            'low_stock_alerted_at' => 'immutable_datetime',
        ];
    }

    /** Available = on_hand − reserved (not stored). */
    public function available(): Quantity
    {
        return $this->on_hand->subtract($this->reserved);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id', 'variant_id');
    }

    protected static function newFactory(): InventoryFactory
    {
        return InventoryFactory::new();
    }
}
