<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Domain\Exceptions\InvalidValue;

/**
 * Width × height of a SQUARE_METER piece, in integer millimetres (width first, RN-QTD-038).
 */
final readonly class Dimensions
{
    public function __construct(public int $widthMm, public int $heightMm)
    {
        if ($widthMm <= 0 || $heightMm <= 0) {
            throw InvalidValue::because('Dimensions must be positive.');
        }
    }

    public static function fromMeters(string $width, string $height): self
    {
        return new self(Length::fromMeters($width)->millimeters(), Length::fromMeters($height)->millimeters());
    }

    public function width(): Length
    {
        return Length::fromMillimeters($this->widthMm);
    }

    public function height(): Length
    {
        return Length::fromMillimeters($this->heightMm);
    }

    /** Area of one piece in thousandths of m² (ADR-019). */
    public function pieceArea(): Quantity
    {
        return AreaCalculator::pieceArea($this->widthMm, $this->heightMm);
    }

    /** Real area for N pieces (no minimum billable area). */
    public function areaFor(int $pieces): Quantity
    {
        return $this->pieceArea()->multiplyBy($pieces);
    }
}
