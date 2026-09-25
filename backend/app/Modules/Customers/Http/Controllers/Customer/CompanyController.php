<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Customer;

use App\Modules\Customers\Actions\UpdateCompanyData;
use App\Modules\Customers\Http\Requests\Customer\UpdateCompanyRequest;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Company;

final class CompanyController
{
    public function update(UpdateCompanyRequest $request, UpdateCompanyData $update): CustomerResource
    {
        $customer = ProfileController::customer($request);
        /** @var Company $company */
        $company = $customer->company()->firstOrFail();
        $update->handle($company, $request->validated());

        return new CustomerResource($customer->setRelation('company', $company));
    }
}
