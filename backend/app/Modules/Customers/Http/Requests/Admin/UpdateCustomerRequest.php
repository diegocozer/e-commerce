<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Admin;

use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Customers\Support\InputNormalizer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /admin/customers/{id} — field-level permissions (API.md §6.3):
 * name/phone → customers.update · price_list_id → pricing.manage · cpf → customers.manage.
 */
final class UpdateCustomerRequest extends FormRequest
{
    private const array PERMISSIONS = [
        'name' => 'customers.update',
        'phone' => 'customers.update',
        'price_list_id' => 'pricing.manage',
        'cpf' => 'customers.manage',
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
        $data = [];
        if ($this->has('phone')) {
            $data['phone'] = InputNormalizer::digits($this->input('phone'));
        }
        if ($this->has('cpf')) {
            $data['cpf'] = InputNormalizer::cpf($this->input('cpf'));
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = (int) $this->route('customer');
        $messages = 'O campo %s não pode ser alterado por aqui.';

        return [
            'name' => ['sometimes', 'required', ...CustomerRules::name(false)],
            'phone' => ['sometimes', 'nullable', ...CustomerRules::phone()],
            'price_list_id' => ['sometimes', 'nullable', 'integer', 'exists:price_lists,id'],
            'cpf' => ['sometimes', 'required', ...CustomerRules::cpf($id)],
            'email' => ['prohibited'],
            'type' => ['prohibited'],
            'password' => ['prohibited'],
            'is_active' => ['prohibited'],
            'marketing_opt_in' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'is_active.prohibited' => 'Use bloquear/desbloquear.',
            'marketing_opt_in.prohibited' => 'O consentimento é do titular.',
            '*.prohibited' => 'O campo :attribute não pode ser alterado.',
        ];
    }
}
