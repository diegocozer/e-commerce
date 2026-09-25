<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;

/** Billing data completeness for the checkout (API.md §2.7 profile_complete / missing_fields). */
final class CustomerProfile
{
    /** @return list<string> */
    public static function missingFields(Customer $customer): array
    {
        $missing = [];
        if ($customer->phone === null || $customer->phone === '') {
            $missing[] = 'phone';
        }

        if ($customer->isCompany()) {
            $company = $customer->company;
            if ($company === null) {
                return [...$missing, 'company.cnpj', 'company.legal_name', 'company.state_registration'];
            }
            if ($company->cnpj === '') {
                $missing[] = 'company.cnpj';
            }
            if ($company->legal_name === '') {
                $missing[] = 'company.legal_name';
            }
            if (! $company->state_registration_exempt && ($company->state_registration === null || $company->state_registration === '')) {
                $missing[] = 'company.state_registration';
            }
        } elseif ($customer->cpf === null || $customer->cpf === '') {
            $missing[] = 'cpf';
        }

        return $missing;
    }

    /** Effective list id: customer → company (null = retail/default). */
    public static function assignedPriceListId(Customer $customer): ?int
    {
        return $customer->price_list_id ?? $customer->company?->price_list_id;
    }
}
