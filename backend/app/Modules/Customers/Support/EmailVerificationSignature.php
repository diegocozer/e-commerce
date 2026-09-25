<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;

/**
 * Signed e-mail verification links for the SPA page (API.md §3.C):
 * {STOREFRONT_URL}/verificar-email?uuid=…&hash=…&expires=…&signature=…
 */
final class EmailVerificationSignature
{
    public const int TTL_MINUTES = 60 * 24 * 3;

    /** @return array{uuid:string, hash:string, expires:int, signature:string} */
    public static function paramsFor(Customer $customer): array
    {
        $params = [
            'uuid' => $customer->uuid,
            'hash' => sha1($customer->email),
            'expires' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ];

        return [...$params, 'signature' => self::sign($params['uuid'], $params['hash'], $params['expires'])];
    }

    public static function url(Customer $customer): string
    {
        return StorefrontUrl::to('verificar-email', self::paramsFor($customer));
    }

    public static function sign(string $uuid, string $hash, int $expires): string
    {
        return hash_hmac('sha256', "email-verification|{$uuid}|{$hash}|{$expires}", (string) config('app.key'));
    }

    public static function isValid(string $uuid, string $hash, int $expires, string $signature): bool
    {
        return $expires >= now()->getTimestamp() && hash_equals(self::sign($uuid, $hash, $expires), $signature);
    }
}
