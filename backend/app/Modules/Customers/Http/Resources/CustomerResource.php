<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Contracts\TermsVersionResolver;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerProfile;
use App\Modules\Customers\Support\PriceListLookup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API.md §2.7 Customer (the customer's own data: documents unmasked, no internal id).
 *
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;
        $customer->loadMissing('company');
        $company = $customer->company;
        $current = app(TermsVersionResolver::class)->current();
        $priceList = app(PriceListLookup::class)->find(CustomerProfile::assignedPriceListId($customer));
        $missing = CustomerProfile::missingFields($customer);

        return [
            'uuid' => $customer->uuid,
            'type' => $customer->type->value,
            'name' => $customer->name,
            'email' => $customer->email,
            'email_verified' => $customer->email_verified_at !== null,
            'phone' => $customer->phone,
            'cpf' => $customer->cpf,
            'marketing_opt_in' => (bool) $customer->marketing_opt_in,
            'company' => $company === null ? null : [
                'legal_name' => $company->legal_name,
                'trade_name' => $company->trade_name,
                'cnpj' => $company->cnpj,
                'state_registration' => $company->state_registration,
                'state_registration_exempt' => $company->state_registration_exempt,
            ],
            'price_list' => $priceList === null ? null : ['code' => $priceList['code'], 'name' => $priceList['name']],
            'terms' => [
                'accepted_version' => $customer->terms_version,
                'current_version' => $current,
                'needs_acceptance' => $customer->terms_version !== $current,
            ],
            'profile_complete' => $missing === [],
            'missing_fields' => $missing,
            'created_at' => $customer->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
