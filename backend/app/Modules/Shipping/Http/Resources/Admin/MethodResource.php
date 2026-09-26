<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Resources\Admin;

use App\Modules\Shipping\Actions\ShippingAdminSupport;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Models\ShippingMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §2.15 ShippingMethod. */
final class MethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShippingMethod $m */
        $m = $this->resource;

        return [
            'id' => $m->id,
            'name' => $m->name,
            'code' => $m->code,
            'type' => $m->type->value,
            'carrier_id' => $m->carrier_id,
            'carrier_service_code' => $m->carrier_service_code,
            'description' => $m->description,
            'delivery_days_min' => $m->delivery_days_min,
            'delivery_days_max' => $m->delivery_days_max,
            'handling_days' => $m->handling_days,
            'weight_basis' => $m->weight_basis->value,
            'cubic_divisor' => $m->cubic_divisor,
            'accepts_free_shipping_coupon' => $m->accepts_free_shipping_coupon,
            'position' => $m->position,
            'is_active' => $m->is_active,
            'pickup' => $m->type === ShippingMethodType::Pickup ? [
                'street' => $m->pickup_street, 'number' => $m->pickup_number, 'complement' => $m->pickup_complement,
                'district' => $m->pickup_district, 'city' => $m->pickup_city, 'state' => $m->pickup_state,
                'postal_code' => $m->pickup_postal_code, 'instructions' => $m->pickup_instructions,
                'opening_hours' => $m->pickup_opening_hours,
            ] : null,
            'rules_count' => (int) ($m->rules_count ?? $m->rules()->count()),
            'created_at' => ShippingAdminSupport::iso($m->created_at),
            'updated_at' => ShippingAdminSupport::iso($m->updated_at),
            'deleted_at' => ShippingAdminSupport::iso($m->deleted_at),
        ];
    }
}
