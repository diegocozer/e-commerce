<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Shared\Domain\Documents\Cnpj;
use App\Shared\Domain\Documents\Cpf;

/** Normalizes masked input before validation (API.md §1.5). Non-strings are left untouched for the validator. */
final class InputNormalizer
{
    public static function digits(mixed $value): mixed
    {
        return is_string($value) ? (string) preg_replace('/\D/', '', $value) : $value;
    }

    public static function email(mixed $value): mixed
    {
        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    public static function cpf(mixed $value): mixed
    {
        return is_string($value) ? Cpf::normalize($value) : $value;
    }

    public static function cnpj(mixed $value): mixed
    {
        return is_string($value) ? Cnpj::normalize($value) : $value;
    }

    /**
     * "ISENTO" (any case) ⇒ exempt with null IE; otherwise digits only.
     *
     * @param  array<string, mixed>  $company
     * @return array<string, mixed>
     */
    public static function stateRegistration(array $company): array
    {
        if (! array_key_exists('state_registration', $company)) {
            return $company;
        }
        $ie = $company['state_registration'];
        if (is_string($ie) && mb_strtoupper(trim($ie)) === 'ISENTO') {
            $company['state_registration'] = null;
            $company['state_registration_exempt'] = true;
        } elseif (is_string($ie)) {
            $digits = (string) preg_replace('/[\s.\-\/]/', '', trim($ie));
            $company['state_registration'] = $digits === '' ? null : $digits;
        }

        return $company;
    }
}
