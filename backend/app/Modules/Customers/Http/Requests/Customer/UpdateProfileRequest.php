<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Customer;

use App\Modules\Customers\Http\Requests\Concerns\ProhibitsDangerousFields;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Customers\Support\InputNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /me (API.md §3.D). */
final class UpdateProfileRequest extends FormRequest
{
    use ProhibitsDangerousFields;

    private const array EXTRA_PROHIBITED = ['email', 'type', 'company', 'password'];

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
        /** @var Customer $customer */
        $customer = $this->user('customer');

        return [
            ...$this->prohibitedRules(self::EXTRA_PROHIBITED),
            'name' => ['sometimes', 'required', ...CustomerRules::name(! $customer->isCompany())],
            'phone' => ['sometimes', 'required', ...CustomerRules::phone()],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'cpf' => [
                'sometimes', 'required',
                static function (string $attribute, mixed $value, Closure $fail) use ($customer): void {
                    if ($customer->cpf !== null) {
                        $fail('CPF não pode ser alterado. Fale conosco.');
                    }
                },
                ...CustomerRules::cpf($customer->id),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->prohibitedMessages(self::EXTRA_PROHIBITED),
            'email.prohibited' => 'A troca de e-mail não está disponível. Fale conosco.',
            'phone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
        ];
    }
}
