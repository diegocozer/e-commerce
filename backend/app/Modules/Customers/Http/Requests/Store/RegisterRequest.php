<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Store;

use App\Modules\Customers\Contracts\TermsVersionResolver;
use App\Modules\Customers\Http\Requests\Concerns\ProhibitsDangerousFields;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPasswordRules;
use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Customers\Support\InputNormalizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** POST /auth/register (API.md §3.C). */
final class RegisterRequest extends FormRequest
{
    use ProhibitsDangerousFields;

    public function authorize(): bool
    {
        return ! Auth::guard('customer')->check();
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['email' => 'email', 'phone' => 'digits', 'cpf' => 'cpf'] as $field => $method) {
            if ($this->has($field)) {
                $data[$field] = InputNormalizer::{$method}($this->input($field));
            }
        }
        if (is_array($company = $this->input('company'))) {
            if (array_key_exists('cnpj', $company)) {
                $company['cnpj'] = InputNormalizer::cnpj($company['cnpj']);
            }
            $data['company'] = InputNormalizer::stateRegistration($company);
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $individual = $this->input('type') !== 'company';
        $pf = fn (): bool => $this->input('type') === 'individual';

        return [
            ...$this->prohibitedRules(),
            'type' => ['required', 'string', Rule::in(['individual', 'company'])],
            'name' => ['required', ...CustomerRules::name($individual)],
            'cpf' => [$individual ? 'required' : 'nullable', ...CustomerRules::cpf()],
            'email' => [
                'required', 'string', 'email:rfc,strict', 'max:191',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && Customer::query()->where('email', $value)->exists()) {
                        $fail('Já existe uma conta com este e-mail.');
                    }
                },
            ],
            'phone' => ['required', ...CustomerRules::phone()],
            'password' => CustomerPasswordRules::rules(is_string($this->input('email')) ? $this->input('email') : null),
            'accept_terms' => ['accepted'],
            'terms_version' => [
                'required', 'string', 'max:20',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== app(TermsVersionResolver::class)->current()) {
                        $fail('Os termos foram atualizados. Recarregue a página e aceite a versão vigente.');
                    }
                },
            ],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            ...($individual && $pf()
                ? [
                    'company' => ['prohibited'],
                    'company.cnpj' => ['prohibited'],
                    'company.legal_name' => ['prohibited'],
                    'company.trade_name' => ['prohibited'],
                    'company.state_registration' => ['prohibited'],
                    'company.state_registration_exempt' => ['prohibited'],
                ]
                : [
                    'company' => ['required', 'array'],
                    'company.cnpj' => ['required', ...CustomerRules::cnpj()],
                    ...CustomerRules::companyData('company.', false),
                ]),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->prohibitedMessages(),
            ...CustomerRules::companyMessages('company.'),
            'accept_terms.accepted' => 'É preciso aceitar os Termos de Uso e a Política de Privacidade.',
            'phone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
        ];
    }
}
