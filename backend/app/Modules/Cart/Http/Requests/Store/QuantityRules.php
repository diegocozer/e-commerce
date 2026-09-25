<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Closure;

/** Wire formats of API.md §1.5 (quantity ≤ 3 decimals; customer dimensions ≤ 2 decimals, 0.01–100 m). */
final class QuantityRules
{
    public static function asString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            $s = var_export($value, true);

            return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
        }

        return is_string($value) ? trim($value) : null;
    }

    public static function quantity(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $s = self::asString($value);
            if ($s === null || preg_match('/^\d{1,6}([.,]\d{1,3})?$/', $s) !== 1 || preg_match('/^0+([.,]0+)?$/', $s) === 1) {
                $fail('Informe uma quantidade válida (maior que zero, até 3 casas decimais).');
            }
        };
    }

    public static function meters(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $s = self::asString($value);
            if ($s === null || preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $s) !== 1) {
                $fail('Informe a medida em metros com até 2 casas decimais (ex.: 1,20).');

                return;
            }
            $cm = (int) round(((float) str_replace(',', '.', $s)) * 100);
            if ($cm < 1 || $cm > 10000) {
                $fail('A medida deve estar entre 0,01 m e 100 m.');
            }
        };
    }
}
