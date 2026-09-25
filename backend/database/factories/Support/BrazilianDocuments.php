<?php

declare(strict_types=1);

namespace Database\Factories\Support;

use App\Shared\Domain\Documents\Cnpj;
use App\Shared\Domain\Documents\Cpf;

/** Valid random CPF/CNPJ generation for factories (check digits computed by the Shared validators). */
final class BrazilianDocuments
{
    public static function cpf(): string
    {
        do {
            $base = sprintf('%09d', random_int(1, 999_999_999));
        } while (preg_match('/^(\d)\1{8}$/', $base) === 1);

        return $base.Cpf::checkDigits($base);
    }

    public static function cnpj(): string
    {
        $base = sprintf('%08d', random_int(1, 99_999_999)).'0001';

        return $base.Cnpj::checkDigits($base);
    }

    /** Alphanumeric CNPJ (in force since 07/2026). */
    public static function alphanumericCnpj(): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $base = '';
        for ($i = 0; $i < 8; $i++) {
            $base .= $chars[random_int(0, 35)];
        }
        $base .= '0001';

        return $base.Cnpj::checkDigits($base);
    }

    /** Mobile phone, digits only (DDD + 9 digits). */
    public static function phone(): string
    {
        return sprintf('47%09d', random_int(900_000_000, 999_999_999));
    }
}
