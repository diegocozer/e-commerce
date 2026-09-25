<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Company;
use App\Modules\Customers\Models\Customer;
use App\Shared\Validation\Rules\Cnpj;
use App\Shared\Validation\Rules\Cpf;
use Closure;

/** Field rules shared by registration, /me and the panel (API.md §3.C/§3.D/§3.G.10). */
final class CustomerRules
{
    /** @return list<mixed> */
    public static function name(bool $individual): array
    {
        return [
            'string', 'min:3', 'max:120',
            static function (string $attribute, mixed $value, Closure $fail) use ($individual): void {
                if (is_string($value) && $value !== strip_tags($value)) {
                    $fail('O nome não pode conter HTML.');
                } elseif ($individual && is_string($value) && count(preg_split('/\s+/u', trim($value)) ?: []) < 2) {
                    $fail('Informe nome e sobrenome.');
                }
            },
        ];
    }

    /** @return list<mixed> */
    public static function phone(): array
    {
        return ['string', 'regex:/^\d{10,11}$/'];
    }

    /** @return list<mixed> CPF valid and unique among active customers */
    public static function cpf(?int $ignoreCustomerId = null): array
    {
        return [
            'string', new Cpf,
            static function (string $attribute, mixed $value, Closure $fail) use ($ignoreCustomerId): void {
                if (! is_string($value)) {
                    return;
                }
                $exists = Customer::query()->where('cpf', $value)
                    ->when($ignoreCustomerId !== null, fn ($q) => $q->whereKeyNot($ignoreCustomerId))
                    ->exists();
                if ($exists) {
                    $fail('Já existe uma conta com este CPF.');
                }
            },
        ];
    }

    /** @return list<mixed> CNPJ (numeric or alphanumeric) valid and unique among companies */
    public static function cnpj(?int $ignoreCompanyId = null): array
    {
        return [
            'string', new Cnpj,
            static function (string $attribute, mixed $value, Closure $fail) use ($ignoreCompanyId): void {
                if (! is_string($value)) {
                    return;
                }
                $exists = Company::query()->where('cnpj', $value)
                    ->when($ignoreCompanyId !== null, fn ($q) => $q->whereKeyNot($ignoreCompanyId))
                    ->exists();
                if ($exists) {
                    $fail('Já existe uma conta com este CNPJ.');
                }
            },
        ];
    }

    /**
     * Company data rules (legal_name, trade_name, IE/exempt) under a prefix ("company." or "").
     *
     * @return array<string, list<mixed>>
     */
    public static function companyData(string $prefix, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            $prefix.'legal_name' => [$required, 'string', 'min:3', 'max:150'],
            $prefix.'trade_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            $prefix.'state_registration_exempt' => [$required, 'boolean'],
            $prefix.'state_registration' => [
                'nullable', 'string', 'regex:/^\d{2,14}$/',
                "prohibited_if_accepted:{$prefix}state_registration_exempt",
                ...($partial ? [] : ["required_unless:{$prefix}state_registration_exempt,true,1"]),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function companyMessages(string $prefix): array
    {
        return [
            $prefix.'state_registration.required_unless' => 'Informe a inscrição estadual ou marque como isento.',
            $prefix.'state_registration.prohibited_if_accepted' => 'Empresa isenta não deve informar inscrição estadual.',
            $prefix.'state_registration.regex' => 'A inscrição estadual deve ter de 2 a 14 dígitos ou "ISENTO".',
        ];
    }
}
