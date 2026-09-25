<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Customer;

use App\Modules\Customers\Actions\ManageAddresses;
use App\Modules\Customers\Http\Resources\AddressResource;
use Illuminate\Http\Request;

final class DefaultAddressController
{
    public function store(Request $request, string $address, ManageAddresses $addresses): AddressResource
    {
        $model = AddressController::find($request, $address, 'update');

        return new AddressResource($addresses->setDefault(ProfileController::customer($request), $model));
    }
}
