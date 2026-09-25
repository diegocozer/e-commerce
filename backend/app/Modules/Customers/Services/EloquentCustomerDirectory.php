<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Customers\DTOs\AddressData;
use App\Modules\Customers\DTOs\CustomerData;
use App\Modules\Customers\DTOs\CustomerPricingProfile;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Support\CustomerProfile;
use App\Modules\Customers\Support\PriceListLookup;

final class EloquentCustomerDirectory implements CustomerDirectory
{
    public function __construct(private readonly PriceListLookup $priceLists) {}

    public function find(int $customerId): ?CustomerData
    {
        $customer = Customer::query()->with('company')->find($customerId);
        if ($customer === null) {
            return null;
        }
        $company = $customer->company;

        return new CustomerData(
            id: $customer->id,
            uuid: $customer->uuid,
            type: $customer->type,
            name: $customer->name,
            email: $customer->email,
            phone: $customer->phone,
            cpf: $customer->cpf,
            companyId: $customer->company_id,
            companyLegalName: $company?->legal_name,
            companyTradeName: $company?->trade_name,
            cnpj: $company?->cnpj,
            stateRegistration: $company?->state_registration,
            stateRegistrationExempt: (bool) $company?->state_registration_exempt,
            isActive: $customer->is_active,
            marketingOptIn: (bool) $customer->marketing_opt_in,
        );
    }

    public function pricingProfile(int $customerId): CustomerPricingProfile
    {
        $customer = Customer::query()->with('company')->findOrFail($customerId);

        return new CustomerPricingProfile(
            customerId: $customer->id,
            companyId: $customer->company_id,
            priceListId: CustomerProfile::assignedPriceListId($customer) ?? $this->priceLists->defaultId(),
        );
    }

    public function addressForCustomer(int $customerId, string $addressUuid): ?AddressData
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $addressUuid) !== 1) {
            return null;
        }

        $address = CustomerAddress::query()
            ->where('customer_id', $customerId)
            ->where('uuid', $addressUuid)
            ->first();

        return $address === null ? null : self::addressData($address);
    }

    public function isProfileCompleteForCheckout(int $customerId): bool
    {
        $customer = Customer::query()->with('company')->find($customerId);

        return $customer !== null && CustomerProfile::missingFields($customer) === [];
    }

    public static function addressData(CustomerAddress $address): AddressData
    {
        return new AddressData(
            id: $address->id,
            uuid: $address->uuid,
            customerId: $address->customer_id,
            label: $address->label,
            recipientName: $address->recipient_name,
            phone: $address->phone,
            postalCode: $address->postal_code,
            street: $address->street,
            number: $address->number,
            complement: $address->complement,
            district: $address->district,
            city: $address->city,
            state: $address->state,
            cityIbgeCode: $address->city_ibge_code,
            reference: $address->reference,
            isDefault: $address->is_default,
        );
    }
}
