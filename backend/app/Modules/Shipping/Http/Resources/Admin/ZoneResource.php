<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Resources\Admin;

use App\Modules\Shipping\Actions\ShippingAdminSupport;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** API.md §2.15 ShippingZone. */
final class ZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ShippingZone $z */
        $z = $this->resource;
        $z->loadMissing(['postalRanges', 'cities', 'states']);

        return [
            'id' => $z->id,
            'name' => $z->name,
            'description' => $z->description,
            'is_active' => $z->is_active,
            'postal_ranges' => $z->postalRanges->sortBy('start_postal_code')->values()->map(fn ($r): array => ['id' => $r->id, 'start_postal_code' => $r->start_postal_code, 'end_postal_code' => $r->end_postal_code])->all(),
            'cities' => $z->cities->sortBy('city_name')->values()->map(fn ($c): array => ['id' => $c->id, 'city_ibge_code' => $c->city_ibge_code, 'city_name' => $c->city_name, 'state' => $c->state])->all(),
            'states' => $z->states->pluck('state')->sort()->values()->all(),
            'rules_count' => (int) ($z->rules_count ?? $z->rules()->count()),
            'created_at' => ShippingAdminSupport::iso($z->created_at),
            'updated_at' => ShippingAdminSupport::iso($z->updated_at),
        ];
    }
}
