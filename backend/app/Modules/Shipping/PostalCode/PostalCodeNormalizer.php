<?php

declare(strict_types=1);

namespace App\Modules\Shipping\PostalCode;

use Illuminate\Validation\ValidationException;

/** CEP normalization (SHIPPING.md §4.8): digits only, exactly 8, not 00000000. */
final class PostalCodeNormalizer
{
    public const string MESSAGE = 'CEP inválido.';

    public static function tryNormalize(string $raw): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^[\d.\-\s]+$/', $raw) !== 1) {
            return null;
        }
        $digits = (string) preg_replace('/\D/', '', $raw);

        return strlen($digits) === 8 && $digits !== '00000000' ? $digits : null;
    }

    /** @throws ValidationException 422 {errors: {<field>: ["CEP inválido."]}} */
    public static function normalize(string $raw, string $field = 'postal_code'): string
    {
        return self::tryNormalize($raw) ?? throw ValidationException::withMessages([$field => [self::MESSAGE]]);
    }
}
