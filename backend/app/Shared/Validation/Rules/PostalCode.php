<?php

declare(strict_types=1);

namespace App\Shared\Validation\Rules;

use App\Shared\Domain\PostalCode as PostalCodeValue;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Validates a CEP ("89010-001" or "89010001"). Store PostalCode::normalize($value). */
final class PostalCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PostalCodeValue::isValid($value)) {
            $fail('O :attribute informado não é um CEP válido.');
        }
    }
}
