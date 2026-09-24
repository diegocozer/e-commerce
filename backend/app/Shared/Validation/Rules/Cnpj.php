<?php

declare(strict_types=1);

namespace App\Shared\Validation\Rules;

use App\Shared\Domain\Documents\Cnpj as CnpjValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates a CNPJ, numeric or alphanumeric (with or without mask). Store Cnpj::normalize($value). */
final class Cnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CnpjValidator::isValid($value)) {
            $fail('O :attribute informado não é um CNPJ válido.');
        }
    }
}
