<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\ManageZones;
use App\Modules\Shipping\Domain\Config\ZoneConfig;
use App\Modules\Shipping\Engine\ZoneMatcher;
use App\Modules\Shipping\Http\Requests\Admin\SaveZoneRequest;
use App\Modules\Shipping\Http\Resources\Admin\ZoneResource;
use App\Modules\Shipping\Models\IbgeCity;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\PostalCode\DestinationResolver;
use App\Modules\Shipping\PostalCode\PostalCodeNormalizer;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ShippingZoneController
{
    public function __construct(private readonly ManageZones $zones) {}

    public function index(): AnonymousResourceCollection
    {
        return ZoneResource::collection(ShippingZone::query()->with(['postalRanges', 'cities', 'states'])->withCount('rules')->orderBy('name')->orderBy('id')->get());
    }

    public function show(ShippingZone $zone): ZoneResource
    {
        return new ZoneResource($zone->loadCount('rules'));
    }

    public function store(SaveZoneRequest $request): JsonResponse
    {
        $zone = $this->zones->create($request->validated(), ActorRef::current());

        return (new ZoneResource($zone->loadCount('rules')))->additional(['warnings' => $this->zones->overlapWarnings($zone)])->response()->setStatusCode(201);
    }

    public function update(SaveZoneRequest $request, ShippingZone $zone): JsonResponse
    {
        $zone = $this->zones->update($zone, $request->validated(), ActorRef::current());

        return (new ZoneResource($zone->unsetRelations()->loadCount('rules')))->additional(['warnings' => $this->zones->overlapWarnings($zone)])->response();
    }

    public function destroy(ShippingZone $zone): Response
    {
        $this->zones->delete($zone, ActorRef::current());

        return response()->noContent();
    }

    /** POST /admin/shipping/zones/{id}/test {postal_code} */
    public function test(Request $request, ShippingZone $zone, DestinationResolver $destinations, ZoneMatcher $matcher): JsonResponse
    {
        $request->validate(['postal_code' => ['required', 'string']]);
        $destination = $destinations->resolve(PostalCodeNormalizer::normalize((string) $request->input('postal_code')));
        $zone->load(['postalRanges', 'cities', 'states']);
        $config = new ZoneConfig(
            $zone->id, $zone->name,
            $zone->postalRanges->map(fn ($r): array => [$r->start_postal_code, $r->end_postal_code])->all(),
            $zone->cities->pluck('city_ibge_code')->all(),
            $zone->states->pluck('state')->all(),
        );
        $match = $matcher->matchZone($destination, $config);

        return new JsonResponse(['data' => [
            'matches' => $match !== null,
            'matched_by' => $match?->matchedBy,
            'destination' => [
                'city' => $destination->city, 'state' => $destination->state,
                'city_ibge_code' => $destination->cityIbgeCode, 'resolved' => $destination->resolved,
            ],
        ]]);
    }

    /** GET /admin/shipping/cities?search=blum&state=SC (up to 20). */
    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'size:2'],
        ]);
        $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) $data['search']));
        $query = IbgeCity::query()
            ->whereRaw('lower(public.f_unaccent(name)) like lower(public.f_unaccent(?))', [$term.'%'])
            ->orderBy('name')->limit(20);
        if (! empty($data['state'])) {
            $query->where('state', strtoupper((string) $data['state']));
        }

        return new JsonResponse(['data' => $query->get()->map(fn (IbgeCity $c): array => ['ibge_code' => $c->ibge_code, 'name' => $c->name, 'state' => $c->state])->all()]);
    }
}
