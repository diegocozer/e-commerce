<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\InventoryMovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Default: an "in" movement consistent with the type/delta CHECK. @extends Factory<InventoryMovement> */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'type' => InventoryMovementType::In,
            'quantity' => '10.000',
            'on_hand_delta' => '10.000',
            'reserved_delta' => '0.000',
            'on_hand_after' => '10.000',
            'reserved_after' => '0.000',
            'reason' => 'Entrada de mercadoria',
            'reference_type' => null,
            'reference_id' => null,
            'admin_user_id' => null,
        ];
    }
}
