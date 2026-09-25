<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Customer;

use App\Modules\Customers\Actions\ManageAddresses;
use App\Modules\Customers\Http\Requests\Customer\AddressRequest;
use App\Modules\Customers\Http\Resources\AddressResource;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** /me/addresses — always scoped to the authenticated customer; other customers' uuids → 404 (SECURITY §5). */
final class AddressController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $addresses = ProfileController::customer($request)->addresses()
            ->orderByDesc('is_default')->orderByDesc('created_at')->orderByDesc('id')->get();

        return AddressResource::collection($addresses);
    }

    public function store(AddressRequest $request, ManageAddresses $addresses): JsonResponse
    {
        $address = $addresses->create(ProfileController::customer($request), $request->addressData());

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $address): AddressResource
    {
        return new AddressResource(self::find($request, $address, 'view'));
    }

    public function update(AddressRequest $request, string $address, ManageAddresses $addresses): AddressResource
    {
        $model = self::find($request, $address, 'update');

        return new AddressResource($addresses->update(ProfileController::customer($request), $model, $request->addressData()));
    }

    public function destroy(Request $request, string $address, ManageAddresses $addresses): Response
    {
        $addresses->delete(ProfileController::customer($request), self::find($request, $address, 'delete'));

        return response()->noContent();
    }

    public static function find(Request $request, string $uuid, string $ability): CustomerAddress
    {
        $customer = ProfileController::customer($request);
        /** @var CustomerAddress $address */
        $address = $customer->addresses()->where('uuid', $uuid)->firstOrFail();
        Gate::forUser($customer)->authorize($ability, $address);

        return $address;
    }
}
