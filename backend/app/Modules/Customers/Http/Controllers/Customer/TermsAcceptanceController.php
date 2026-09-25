<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Customer;

use App\Modules\Customers\Http\Requests\Customer\AcceptTermsRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;

final class TermsAcceptanceController
{
    public function store(AcceptTermsRequest $request): CustomerResource
    {
        $customer = ProfileController::customer($request);
        $customer->terms_version = (string) $request->validated('terms_version');
        $customer->terms_accepted_at = now();
        $customer->terms_accepted_ip = $request->ip();
        $customer->save();

        return new CustomerResource($customer);
    }
}
