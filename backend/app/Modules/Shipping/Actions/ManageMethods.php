<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;

final class ManageMethods
{
    public function __construct(private readonly ShippingAdminSupport $support) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ActorRef $actor): ShippingMethod
    {
        return DB::transaction(function () use ($data, $actor): ShippingMethod {
            $type = ShippingMethodType::from((string) $data['type']);
            $attributes = self::attributes($data) + [
                'type' => $type,
                // Default per type (SHIPPING.md §5.1): true for own_delivery/table_rate.
                'accepts_free_shipping_coupon' => $type === ShippingMethodType::OwnDelivery || $type === ShippingMethodType::TableRate,
                'position' => (int) ShippingMethod::query()->max('position') + 10,
            ];
            $method = ShippingMethod::query()->create($attributes)->refresh();
            $this->support->record($actor, 'shipping_method', 'created', $method->id, [], ShippingAdminSupport::snapshot($method));

            return $method;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ShippingMethod $method, array $data, ActorRef $actor): ShippingMethod
    {
        return DB::transaction(function () use ($method, $data, $actor): ShippingMethod {
            $method = ShippingMethod::query()->lockForUpdate()->findOrFail($method->id);
            $this->support->assertFresh($method, $data['expected_updated_at'] ?? null);
            $before = ShippingAdminSupport::snapshot($method);
            $method->update(self::attributes($data));
            $this->support->record($actor, 'shipping_method', 'updated', $method->id, $before, ShippingAdminSupport::snapshot($method));

            return $method;
        });
    }

    public function delete(ShippingMethod $method, ActorRef $actor): void
    {
        DB::transaction(function () use ($method, $actor): void {
            $before = ShippingAdminSupport::snapshot($method);
            $method->delete();
            $this->support->record($actor, 'shipping_method', 'deleted', $method->id, $before, []);
        });
    }

    /** @param  list<int>  $ids */
    public function reorder(array $ids, ActorRef $actor): void
    {
        DB::transaction(function () use ($ids, $actor): void {
            foreach (array_values($ids) as $index => $id) {
                ShippingMethod::query()->whereKey($id)->first()?->update(['position' => $index * 10]);
            }
            $this->support->record($actor, 'shipping_method', 'reordered', 0, [], ['ids' => array_values($ids)]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'name', 'code', 'carrier_id', 'carrier_service_code', 'description', 'delivery_days_min', 'delivery_days_max',
            'handling_days', 'weight_basis', 'cubic_divisor', 'accepts_free_shipping_coupon', 'position', 'is_active',
        ]));
        if (array_key_exists('pickup', $data) && is_array($data['pickup'])) {
            $p = $data['pickup'];
            $attributes += [
                'pickup_street' => $p['street'] ?? null, 'pickup_number' => $p['number'] ?? null,
                'pickup_complement' => $p['complement'] ?? null, 'pickup_district' => $p['district'] ?? null,
                'pickup_city' => $p['city'] ?? null, 'pickup_state' => $p['state'] ?? null,
                'pickup_postal_code' => isset($p['postal_code']) ? preg_replace('/\D/', '', (string) $p['postal_code']) : null,
                'pickup_instructions' => $p['instructions'] ?? null, 'pickup_opening_hours' => $p['opening_hours'] ?? null,
            ];
        }

        return $attributes;
    }
}
