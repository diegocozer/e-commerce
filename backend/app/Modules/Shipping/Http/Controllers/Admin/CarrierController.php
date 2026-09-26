<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\ManageCarriers;
use App\Modules\Shipping\Http\Requests\Admin\SaveCarrierRequest;
use App\Modules\Shipping\Http\Resources\Admin\CarrierResource;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Shared\Domain\ActorRef;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class CarrierController
{
    public function __construct(private readonly ManageCarriers $carriers) {}

    public function index(): AnonymousResourceCollection
    {
        return CarrierResource::collection(ShippingCarrier::query()->withCount('methods')->orderBy('name')->orderBy('id')->get());
    }

    public function show(ShippingCarrier $carrier): CarrierResource
    {
        return new CarrierResource($carrier->loadCount('methods'));
    }

    public function store(SaveCarrierRequest $request): JsonResponse
    {
        $carrier = $this->carriers->create($request->validated(), ActorRef::current());

        return (new CarrierResource($carrier->loadCount('methods')))->response()->setStatusCode(201);
    }

    public function update(SaveCarrierRequest $request, ShippingCarrier $carrier): CarrierResource
    {
        return new CarrierResource($this->carriers->update($carrier, $request->validated(), ActorRef::current())->loadCount('methods'));
    }

    public function destroy(ShippingCarrier $carrier): Response
    {
        $this->carriers->delete($carrier, ActorRef::current());

        return response()->noContent();
    }
}
