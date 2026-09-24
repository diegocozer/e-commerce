<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use ArithmeticError;
use DivisionByZeroError;

/**
 * Integer-only arithmetic helpers (ADR-003: never floats for money/quantities).
 */
final class Rounding
{
    /**
     * Integer division rounding half away from zero ("round half up" for
     * positive values: 8506.5 → 8507; -2.5 → -3).
     */
    public static function halfUpDiv(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new DivisionByZeroError('Division by zero.');
        }

        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if ($remainder !== 0 && abs($remainder) * 2 >= abs($denominator)) {
            $quotient += (($numerator < 0) xor ($denominator < 0)) ? -1 : 1;
        }

        return $quotient;
    }

    /** Integer division rounding towards +infinity (e.g. "per started kg"). */
    public static function ceilDiv(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new DivisionByZeroError('Division by zero.');
        }

        $quotient = intdiv($numerator, $denominator);

        if ($numerator % $denominator !== 0 && (($numerator < 0) === ($denominator < 0))) {
            $quotient++;
        }

        return $quotient;
    }

    /** Multiplication that fails loudly instead of silently overflowing to float. */
    public static function multiply(int $a, int $b): int
    {
        $result = $a * $b;

        if (! is_int($result)) {
            throw new ArithmeticError('Integer overflow.');
        }

        return $result;
    }
}
