<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Redaction of sensitive keys in arbitrary nested arrays (SECURITY.md §17).
 * Used by the log processor and by the audit logger.
 *  - secrets (passwords, tokens, card data, credentials…) → "[REDACTED]";
 *  - personal documents (cpf, cnpj, document) → masked.
 */
final class SensitiveData
{
    public const string REDACTED = '[REDACTED]';

    /** Exact key names (case-insensitive). */
    private const array SECRET_KEYS = [
        'password', 'current_password', 'password_confirmation', 'token', 'secret', 'authorization',
        'cookie', 'x-xsrf-token', 'x-signature', 'cvv', 'access_token', 'api_key', 'credentials',
        'remember_token', 'set-cookie',
    ];

    /** Key patterns (case-insensitive, fnmatch syntax). */
    private const array SECRET_PATTERNS = ['password*', '*_token', '*_secret', 'card*', '*_password'];

    private const array DOCUMENT_KEYS = ['cpf', 'cnpj', 'document', 'customer_document', 'picked_up_by_document'];

    public static function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::SECRET_KEYS, true)) {
            return true;
        }

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    public static function isDocumentKey(string $key): bool
    {
        return in_array(strtolower($key), self::DOCUMENT_KEYS, true);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return [self::REDACTED];
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_string($key) && self::isDocumentKey($key) && is_string($value)) {
                $data[$key] = Mask::document($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value, $depth + 1);
            }
        }

        return $data;
    }
}
