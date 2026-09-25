<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Customer;

use App\Modules\Customers\Actions\UpdateCustomerProfile;
use App\Modules\Customers\Http\Requests\Customer\UpdateProfileRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;

final class ProfileController
{
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource(self::customer($request));
    }

    public function update(UpdateProfileRequest $request, UpdateCustomerProfile $update): CustomerResource
    {
        return new CustomerResource($update->handle(self::customer($request), $request->validated()));
    }

    public static function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user('customer');
    }
}
