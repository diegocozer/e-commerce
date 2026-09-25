<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Customer;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /me/password */
final class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Customer $customer */
        $customer = $this->user('customer');

        return [
            'current_password' => ['required', 'string', 'current_password:customer'],
            'password' => [...CustomerPasswordRules::rules($customer->email), 'different:current_password'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Senha atual incorreta.',
            'password.different' => 'A nova senha deve ser diferente da atual.',
        ];
    }
}
