<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Resources\Admin;

use App\Modules\Shipping\Actions\ShippingAdminSupport;
use App\Modules\Shipping\Models\ShippingCarrier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §2.15 Carrier — credentials are never returned. @mixin ShippingCarrier */
final class CarrierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShippingCarrier $c */
        $c = $this->resource;

        return [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'driver' => $c->driver,
            'settings' => (object) ($c->settings ?? []),
            'has_credentials' => $c->getRawOriginal('credentials') !== null,
            'is_active' => $c->is_active,
            'methods_count' => (int) ($c->methods_count ?? $c->methods()->count()),
            'created_at' => ShippingAdminSupport::iso($c->created_at),
            'updated_at' => ShippingAdminSupport::iso($c->updated_at),
        ];
    }
}
