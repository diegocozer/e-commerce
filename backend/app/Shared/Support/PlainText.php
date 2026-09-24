<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Normalizer;

/**
 * Plain text normalization for free-text fields (SECURITY.md §8): strips tags,
 * removes control characters (keeps \n), normalizes Unicode to NFC and trims.
 */
final class PlainText
{
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strip_tags($value);
        $value = preg_replace('/[^\P{C}\n]/u', '', $value) ?? '';
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return trim($normalized === false ? $value : $normalized);
    }
}
