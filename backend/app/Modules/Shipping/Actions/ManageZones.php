<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Shipping\Exceptions\ResourceInUse;
use App\Modules\Shipping\Models\IbgeCity;
use App\Modules\Shipping\Models\ShippingRule;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZonePostalRange;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;

final class ManageZones
{
    public function __construct(private readonly ShippingAdminSupport $support) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ActorRef $actor): ShippingZone
    {
        return DB::transaction(function () use ($data, $actor): ShippingZone {
            $zone = ShippingZone::query()->create(array_intersect_key($data, array_flip(['name', 'description', 'is_active'])));
            $this->syncLocations($zone, $data);
            $this->support->record($actor, 'shipping_zone', 'created', $zone->id, [], $this->auditState($zone));

            return $zone;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ShippingZone $zone, array $data, ActorRef $actor): ShippingZone
    {
        return DB::transaction(function () use ($zone, $data, $actor): ShippingZone {
            $zone = ShippingZone::query()->lockForUpdate()->findOrFail($zone->id);
            $this->support->assertFresh($zone, $data['expected_updated_at'] ?? null);
            $before = $this->auditState($zone);
            $zone->fill(array_intersect_key($data, array_flip(['name', 'description', 'is_active'])));
            if (array_intersect_key($data, array_flip(['postal_ranges', 'cities', 'states'])) !== []) {
                $zone->updated_at = now(); // location changes also touch the zone
            }
            $zone->save();
            $this->syncLocations($zone, $data);
            $this->support->record($actor, 'shipping_zone', 'updated', $zone->id, $before, $this->auditState($zone));

            return $zone;
        });
    }

    public function delete(ShippingZone $zone, ActorRef $actor): void
    {
        $rules = ShippingRule::withTrashed()->where('zone_id', $zone->id)->orderBy('id')->get();
        if ($rules->isNotEmpty()) {
            throw new ResourceInUse('Zona usada por regras de frete.', $rules->map(
                static fn (ShippingRule $r): array => ['type' => 'shipping_rule', 'id' => $r->id, 'label' => $r->name],
            )->all());
        }

        DB::transaction(function () use ($zone, $actor): void {
            $before = $this->auditState($zone);
            $zone->delete();
            $this->support->record($actor, 'shipping_zone', 'deleted', $zone->id, $before, []);
        });
    }

    /** @return list<string> overlap warnings with ranges of other zones */
    public function overlapWarnings(ShippingZone $zone): array
    {
        $warnings = [];
        foreach ($zone->postalRanges()->orderBy('start_postal_code')->get() as $range) {
            $others = ShippingZonePostalRange::query()->with('zone')
                ->where('zone_id', '!=', $zone->id)
                ->where('start_postal_code', '<=', $range->end_postal_code)
                ->where('end_postal_code', '>=', $range->start_postal_code)
                ->get();
            foreach ($others as $other) {
                $warnings[] = "Faixa {$range->start_postal_code}–{$range->end_postal_code} sobrepõe {$other->start_postal_code}–{$other->end_postal_code} da zona \"{$other->zone?->name}\".";
            }
        }

        return $warnings;
    }

    /** @param  array<string, mixed>  $data */
    private function syncLocations(ShippingZone $zone, array $data): void
    {
        if (array_key_exists('postal_ranges', $data)) {
            $zone->postalRanges()->delete();
            foreach ((array) $data['postal_ranges'] as $range) {
                $zone->postalRanges()->create([
                    'start_postal_code' => preg_replace('/\D/', '', (string) $range['start_postal_code']),
                    'end_postal_code' => preg_replace('/\D/', '', (string) $range['end_postal_code']),
                ]);
            }
        }
        if (array_key_exists('cities', $data)) {
            $zone->cities()->delete();
            $codes = array_map(static fn (array $c): string => (string) $c['city_ibge_code'], (array) $data['cities']);
            foreach (IbgeCity::query()->whereIn('ibge_code', $codes)->orderBy('name')->get() as $city) {
                $zone->cities()->create(['city_ibge_code' => $city->ibge_code, 'city_name' => $city->name, 'state' => $city->state]);
            }
        }
        if (array_key_exists('states', $data)) {
            $zone->states()->delete();
            foreach (array_unique((array) $data['states']) as $state) {
                $zone->states()->create(['state' => $state]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function auditState(ShippingZone $zone): array
    {
        return ShippingAdminSupport::snapshot($zone) + [
            'postal_ranges' => $zone->postalRanges()->orderBy('start_postal_code')->get()->map(fn ($r) => $r->start_postal_code.'-'.$r->end_postal_code)->implode(','),
            'cities' => $zone->cities()->orderBy('city_ibge_code')->pluck('city_ibge_code')->implode(','),
            'states' => $zone->states()->orderBy('state')->pluck('state')->implode(','),
        ];
    }
}
