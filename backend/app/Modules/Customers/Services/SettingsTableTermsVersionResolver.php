<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\TermsVersionResolver;
use Illuminate\Support\Facades\DB;

/** Reads `legal.terms_version` from the settings table (read-only; falls back to the seed default). */
final class SettingsTableTermsVersionResolver implements TermsVersionResolver
{
    public const string FALLBACK = '2026-01';

    public function current(): string
    {
        $raw = DB::table('settings')->where('key', 'legal.terms_version')->value('value');
        $value = is_string($raw) ? json_decode($raw, true) : null;

        return is_string($value) && $value !== '' ? $value : self::FALLBACK;
    }
}
