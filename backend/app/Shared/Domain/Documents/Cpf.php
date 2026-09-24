<?php

declare(strict_types=1);

namespace App\Shared\Domain\Documents;

/** CPF validation (11 digits, two mod-11 check digits). */
final class Cpf
{
    public static function normalize(string $raw): string
    {
        return preg_replace('/[\s.\-]/', '', trim($raw)) ?? '';
    }

    public static function isValid(string $raw): bool
    {
        $cpf = self::normalize($raw);

        if (preg_match('/^\d{11}$/', $cpf) !== 1 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        return substr($cpf, 9, 2) === self::checkDigits(substr($cpf, 0, 9));
    }

    /** Check digits for the first 9 digits. */
    public static function checkDigits(string $base): string
    {
        $digits = array_map('intval', str_split($base));
        $first = self::digit($digits, 10);
        $digits[] = $first;
        $second = self::digit($digits, 11);

        return $first.$second;
    }

    /** "529.982.247-25" */
    public static function format(string $cpf): string
    {
        $cpf = self::normalize($cpf);

        return sprintf('%s.%s.%s-%s', substr($cpf, 0, 3), substr($cpf, 3, 3), substr($cpf, 6, 3), substr($cpf, 9, 2));
    }

    /** @param  list<int>  $digits */
    private static function digit(array $digits, int $startWeight): int
    {
        $sum = 0;
        foreach ($digits as $i => $digit) {
            $sum += $digit * ($startWeight - $i);
        }
        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
