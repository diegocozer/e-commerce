<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Logistics;

use InvalidArgumentException;

/** Exact cm ⇄ mm conversions without float (SHIPPING.md §2.1). */
final class Dimension
{
    /** "12.5" → 125, "130" → 1300, "0.05" → 1 (rounded up to the mm). */
    public static function cmToMm(string|int $cm): int
    {
        $cm = trim((string) $cm);
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $cm, $m) !== 1) {
            throw new InvalidArgumentException('Invalid centimetre value.');
        }
        $fraction = $m[2] ?? '';
        $tenths = (int) (($fraction.'0')[0]);
        $rest = substr($fraction, 1);

        return (int) $m[1] * 10 + $tenths + ($rest !== '' && (int) $rest > 0 ? 1 : 0);
    }

    /** 1325 → "132.5" */
    public static function mmToCm(int $mm): string
    {
        return intdiv($mm, 10).'.'.($mm % 10);
    }

    /** 1325 → 132.5 (JSON number, 1 decimal) */
    public static function mmToCmNumber(int $mm): float|int
    {
        return $mm % 10 === 0 ? intdiv($mm, 10) : (float) self::mmToCm($mm);
    }
}
