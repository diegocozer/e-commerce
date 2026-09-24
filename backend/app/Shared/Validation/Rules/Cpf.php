<?php

declare(strict_types=1);

namespace App\Shared\Validation\Rules;

use App\Shared\Domain\Documents\Cpf as CpfValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates a CPF (with or without mask). Store Cpf::normalize($value). */
final class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! CpfValidator::isValid($value)) {
            $fail('O :attribute informado não é um CPF válido.');
        }
    }
}
