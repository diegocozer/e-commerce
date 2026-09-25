<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Customer;

use App\Modules\Customers\Http\Requests\Concerns\ProhibitsDangerousFields;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Customers\Support\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /me/company (PJ only; CNPJ immutable — RN-CLI-005). */
final class UpdateCompanyRequest extends FormRequest
{
    use ProhibitsDangerousFields;

    public function authorize(): bool
    {
        /** @var Customer $customer */
        $customer = $this->user('customer');

        return $customer->isCompany();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(InputNormalizer::stateRegistration($this->only(['state_registration', 'state_registration_exempt'])));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedRules(['cnpj']),
            ...CustomerRules::companyData('', true),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->prohibitedMessages(['cnpj']),
            ...CustomerRules::companyMessages(''),
            'cnpj.prohibited' => 'O CNPJ não pode ser alterado. Fale conosco.',
        ];
    }
}
