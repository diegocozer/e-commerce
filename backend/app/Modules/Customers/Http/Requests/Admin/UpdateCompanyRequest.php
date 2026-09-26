<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Admin;

use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Customers\Support\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /admin/companies/{id}: data → customers.update · price_list_id → pricing.manage · cnpj → customers.manage. */
final class UpdateCompanyRequest extends FormRequest
{
    private const array PERMISSIONS = [
        'legal_name' => 'customers.update',
        'trade_name' => 'customers.update',
        'state_registration' => 'customers.update',
        'state_registration_exempt' => 'customers.update',
        'price_list_id' => 'pricing.manage',
        'cnpj' => 'customers.manage',
    ];

    public function authorize(): bool
    {
        $admin = $this->user('admin');
        foreach (self::PERMISSIONS as $field => $permission) {
            if ($this->has($field) && ! $admin->can($permission)) {
                return false;
            }
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = InputNormalizer::stateRegistration($this->only(['state_registration', 'state_registration_exempt']));
        if ($this->has('cnpj')) {
            $data['cnpj'] = InputNormalizer::cnpj($this->input('cnpj'));
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...CustomerRules::companyData('', true),
            'price_list_id' => ['sometimes', 'nullable', 'integer', 'exists:price_lists,id'],
            'cnpj' => ['sometimes', 'required', ...CustomerRules::cnpj((int) $this->route('company'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return CustomerRules::companyMessages('');
    }
}
