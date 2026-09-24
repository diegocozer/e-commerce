<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Masking of personal data for logs, audit and listings (SECURITY.md §17).
 */
final class Mask
{
    /** "52998224725" → "***.982.247-**" */
    public static function cpf(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) !== 11) {
            return '***';
        }

        return sprintf('***.%s.%s-**', substr($digits, 3, 3), substr($digits, 6, 3));
    }

    /** "11222333000181" → "**.222.333/0001-**" (alphanumeric CNPJs included). */
    public static function cnpj(string $value): string
    {
        $chars = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? '');
        if (strlen($chars) !== 14) {
            return '***';
        }

        return sprintf('**.%s.%s/%s-**', substr($chars, 2, 3), substr($chars, 5, 3), substr($chars, 8, 4));
    }

    /** CPF or CNPJ, detected by length. */
    public static function document(string $value): string
    {
        $chars = preg_replace('/[^0-9A-Za-z]/', '', $value) ?? '';

        return strlen($chars) === 11 ? self::cpf($chars) : self::cnpj($chars);
    }

    /** "joao.silva@gmail.com" → "jo***@g***.com" */
    public static function email(string $value): string
    {
        $parts = explode('@', trim($value), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '***';
        }

        [$local, $domain] = $parts;
        $dot = strrpos($domain, '.');
        $domainName = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);

        return mb_substr($local, 0, 2).'***@'.mb_substr($domainName, 0, 1).'***'.$tld;
    }

    /** "47999990001" / "5547999990001" → "(47) *****-0001" */
    public static function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) < 10) {
            return '***';
        }

        return sprintf('(%s) *****-%s', substr($digits, 0, 2), substr($digits, -4));
    }
}
