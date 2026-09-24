<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;

/**
 * SQUARE_METER area rules (ADR-019, supersedes ADR-003/004):
 *  - area per piece (milli-m²) = round_half_up(width_mm × height_mm / 1000);
 *  - minimum billable area applies PER PIECE:
 *    billable = max(piece_area, min_billable_area) × pieces;
 *  - stock quantity = piece_area × pieces (no minimum).
 */
final class AreaCalculator
{
    public static function pieceArea(int $widthMm, int $heightMm): Quantity
    {
        if ($widthMm <= 0 || $heightMm <= 0) {
            throw InvalidValue::because('Dimensions must be positive.');
        }

        return Quantity::fromMilli(Rounding::halfUpDiv(Rounding::multiply($widthMm, $heightMm), 1000));
    }

    public static function calculate(int $widthMm, int $heightMm, int $pieces, ?Quantity $minBillableArea = null): AreaCalculation
    {
        if ($pieces <= 0) {
            throw InvalidValue::because('Pieces must be positive.');
        }

        $pieceArea = self::pieceArea($widthMm, $heightMm);
        $minimumApplied = $minBillableArea !== null && $pieceArea->lessThan($minBillableArea);
        $billablePerPiece = $minimumApplied ? $minBillableArea : $pieceArea;

        return new AreaCalculation(
            pieceArea: $pieceArea,
            pieces: $pieces,
            stockQuantity: $pieceArea->multiplyBy($pieces),
            billableQuantity: $billablePerPiece->multiplyBy($pieces),
            minimumApplied: $minimumApplied,
        );
    }

    public static function forDimensions(Dimensions $dimensions, int $pieces, ?Quantity $minBillableArea = null): AreaCalculation
    {
        return self::calculate($dimensions->widthMm, $dimensions->heightMm, $pieces, $minBillableArea);
    }
}
