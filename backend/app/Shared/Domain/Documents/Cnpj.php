<?php

declare(strict_types=1);

namespace App\Shared\Domain\Documents;

/**
 * CNPJ validation, including the alphanumeric format in force since 07/2026
 * (IN RFB 2.229/2024): 12 characters [0-9A-Z] + 2 numeric check digits.
 * Each character is valued as (ASCII code − 48), so digits keep their value
 * and 'A' = 17 … 'Z' = 42; weights and mod-11 rule are the classic ones.
 */
final class Cnpj
{
    private const array FIRST_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    private const array SECOND_WEIGHTS = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Removes mask characters and upper-cases: "12.abc.345/01de-35" → "12ABC34501DE35". */
    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[\s.\/\-]/', '', trim($raw)) ?? '');
    }

    public static function isValid(string $raw): bool
    {
        $cnpj = self::normalize($raw);

        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) !== 1 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        return substr($cnpj, 12, 2) === self::checkDigits(substr($cnpj, 0, 12));
    }

    public static function isAlphanumeric(string $cnpj): bool
    {
        return preg_match('/[A-Z]/', self::normalize($cnpj)) === 1;
    }

    /** Check digits for the first 12 characters. */
    public static function checkDigits(string $base): string
    {
        $values = array_map(static fn (string $char): int => ord($char) - 48, str_split(strtoupper($base)));
        $first = self::digit($values, self::FIRST_WEIGHTS);
        $values[] = $first;
        $second = self::digit($values, self::SECOND_WEIGHTS);

        return $first.$second;
    }

    /** "11.222.333/0001-81" */
    public static function format(string $cnpj): string
    {
        $cnpj = self::normalize($cnpj);

        return sprintf('%s.%s.%s/%s-%s', substr($cnpj, 0, 2), substr($cnpj, 2, 3), substr($cnpj, 5, 3), substr($cnpj, 8, 4), substr($cnpj, 12, 2));
    }

    /**
     * @param  list<int>  $values
     * @param  list<int>  $weights
     */
    private static function digit(array $values, array $weights): int
    {
        $sum = 0;
        foreach ($weights as $i => $weight) {
            $sum += $values[$i] * $weight;
        }
        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
