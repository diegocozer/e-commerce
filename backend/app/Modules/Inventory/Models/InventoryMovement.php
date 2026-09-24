<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\InventoryMovementType;
use App\Shared\Casts\QuantityCast;
use App\Shared\Domain\Quantity;
use Database\Factories\Inventory\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Immutable stock movement (DATABASE.md §3.3.2). `reference_type` holds a
 * morph alias (e.g. "order"). UPDATE/DELETE are blocked by a trigger.
 *
 * @property int $id
 * @property int $variant_id
 * @property InventoryMovementType $type
 * @property Quantity $quantity
 * @property Quantity $on_hand_delta
 * @property Quantity $reserved_delta
 * @property Quantity $on_hand_after
 * @property Quantity $reserved_after
 * @property string|null $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $admin_user_id
 */
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'inventory_movements';

    protected $fillable = [
        'variant_id', 'type', 'quantity', 'on_hand_delta', 'reserved_delta', 'on_hand_after', 'reserved_after',
        'reason', 'reference_type', 'reference_id', 'admin_user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => QuantityCast::class,
            'on_hand_delta' => QuantityCast::class,
            'reserved_delta' => QuantityCast::class,
            'on_hand_after' => QuantityCast::class,
            'reserved_after' => QuantityCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Inventory, $this> */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'variant_id', 'variant_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): InventoryMovementFactory
    {
        return InventoryMovementFactory::new();
    }
}
