<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Support\AddressPostalCodeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Address book (RN-CLI-020..024): max 10, exactly one default, city/UF/IBGE
 * derived from the CEP, soft delete promoting the most recent one.
 * The customer row is locked to serialize concurrent changes.
 */
final class ManageAddresses
{
    public const int LIMIT = 10;

    private const array FIELDS = [
        'label', 'recipient_name', 'phone', 'street', 'number', 'complement', 'district', 'reference',
    ];

    public function __construct(private readonly AddressPostalCodeResolver $postalCodes) {}

    /** @param  array<string, mixed>  $data */
    public function create(Customer $customer, array $data): CustomerAddress
    {
        $info = $this->postalCodes->resolve((string) $data['postal_code']);

        return DB::transaction(function () use ($customer, $data, $info): CustomerAddress {
            $this->lock($customer);
            $count = $customer->addresses()->count();
            if ($count >= self::LIMIT) {
                throw ValidationException::withMessages(['address' => 'Limite de 10 endereços.']);
            }

            $address = new CustomerAddress(array_intersect_key($data, array_flip(self::FIELDS)));
            $address->postal_code = $info->postalCode;
            $address->city = $info->city;
            $address->state = $info->state;
            $address->city_ibge_code = $info->cityIbgeCode;
            $address->customer_id = $customer->id;
            $address->is_default = false;
            $address->save();

            if ($count === 0 || (bool) ($data['is_default'] ?? false)) {
                $this->makeDefault($customer, $address);
            }

            return $address->refresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Customer $customer, CustomerAddress $address, array $data): CustomerAddress
    {
        $info = isset($data['postal_code']) ? $this->postalCodes->resolve((string) $data['postal_code']) : null;

        return DB::transaction(function () use ($customer, $address, $data, $info): CustomerAddress {
            $this->lock($customer);
            $address->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if ($info !== null) {
                $address->postal_code = $info->postalCode;
                $address->city = $info->city;
                $address->state = $info->state;
                $address->city_ibge_code = $info->cityIbgeCode;
            }
            $address->save();

            if (($data['is_default'] ?? null) === true || ($data['is_default'] ?? null) === 1 || ($data['is_default'] ?? null) === '1') {
                $this->makeDefault($customer, $address);
            }

            return $address->refresh();
        });
    }

    public function setDefault(Customer $customer, CustomerAddress $address): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $address): CustomerAddress {
            $this->lock($customer);
            $this->makeDefault($customer, $address);

            return $address->refresh();
        });
    }

    public function delete(Customer $customer, CustomerAddress $address): void
    {
        DB::transaction(function () use ($customer, $address): void {
            $this->lock($customer);
            $wasDefault = $address->is_default;
            $address->is_default = false;
            $address->save();
            $address->delete();

            if ($wasDefault) {
                $next = $customer->addresses()->latest('created_at')->latest('id')->first();
                if ($next !== null) {
                    $this->makeDefault($customer, $next);
                }
            }
        });
    }

    private function makeDefault(Customer $customer, CustomerAddress $address): void
    {
        $customer->addresses()->whereKeyNot($address->id)->where('is_default', true)->update(['is_default' => false]);
        $address->is_default = true;
        $address->save();
    }

    private function lock(Customer $customer): void
    {
        Customer::query()->whereKey($customer->id)->lockForUpdate()->first(['id']);
    }
}
