<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\DTOs\BillableQuantity;
use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Shared\Domain\AreaCalculator;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Rounding;
use App\Shared\Domain\SaleUnit;

/**
 * Pure sale-quantity rules (ADR-004 / ADR-019, RN-QTD). Never rounds the
 * customer's input silently: invalid input is rejected with suggestions.
 */
final class DefaultSaleQuantityResolver implements SaleQuantityResolver
{
    /** RN-QTD-006 sanity limits. */
    public const int MAX_QUANTITY_MILLI = 100_000_000;

    public const int MAX_DIMENSION_MM = 100_000;

    public const int MIN_DIMENSION_MM = 10;

    public const int MAX_PIECES = 1000;

    public function resolve(VariantData $variant, SaleInput $input): BillableQuantity
    {
        return $variant->saleUnit->usesDimensions()
            ? $this->resolveArea($variant, $input)
            : $this->resolveQuantity($variant, $input);
    }

    private function resolveQuantity(VariantData $variant, SaleInput $input): BillableQuantity
    {
        $quantity = $input->quantity;
        if ($quantity === null) {
            throw InvalidSaleQuantity::on('quantity', 'Informe a quantidade.', 'required');
        }

        $this->checkQuantityRules($variant, $quantity, 'quantity', $variant->saleUnit->abbreviation());

        return new BillableQuantity(
            billable: $quantity,
            stock: $quantity,
            pieceArea: null,
            minimumAreaApplied: false,
            weightGrams: self::lineWeight($variant, $quantity),
        );
    }

    private function resolveArea(VariantData $variant, SaleInput $input): BillableQuantity
    {
        $pieces = $input->pieces ?? 1;
        if ($pieces < 1 || $pieces > self::MAX_PIECES) {
            throw InvalidSaleQuantity::on('pieces', 'Informe entre 1 e 1.000 peças.', 'range');
        }
        // min/max/step of SQUARE_METER refer to pieces (ADR-019).
        $this->checkQuantityRules($variant, Quantity::ofUnits($pieces), 'pieces', $pieces === 1 ? 'peça' : 'peças', integerOnly: true);

        $width = $this->resolveWidth($variant, $input->widthMm, $input->heightMm);
        $height = $input->heightMm;
        if ($height === null) {
            throw InvalidSaleQuantity::on('height_m', 'Informe a altura.', 'required');
        }
        $this->checkDimension($height, 'height_m', 'Altura');
        if ($variant->minHeightMm !== null && $height < $variant->minHeightMm) {
            throw InvalidSaleQuantity::on('height_m', 'Altura mínima: '.self::meters($variant->minHeightMm).'.', 'min');
        }
        if ($variant->maxHeightMm !== null && $height > $variant->maxHeightMm) {
            throw InvalidSaleQuantity::on('height_m', 'Altura máxima: '.self::meters($variant->maxHeightMm).'.', 'max');
        }

        $calc = AreaCalculator::calculate($width, $height, $pieces, $variant->minBillableArea);

        return new BillableQuantity(
            billable: $calc->billableQuantity,
            stock: $calc->stockQuantity,
            pieceArea: $calc->pieceArea,
            minimumAreaApplied: $calc->minimumApplied,
            widthMm: $width,
            heightMm: $height,
            pieces: $pieces,
            weightGrams: self::lineWeight($variant, $calc->billableQuantity),
        );
    }

    private function resolveWidth(VariantData $variant, ?int $widthMm, ?int $heightMm): int
    {
        if ($variant->fixedWidthMm !== null) {
            if ($widthMm !== null && $widthMm !== $variant->fixedWidthMm) {
                throw InvalidSaleQuantity::on('width_m', 'A largura deste produto é fixa em '.self::meters($variant->fixedWidthMm).'.', 'fixed_width');
            }

            return $variant->fixedWidthMm;
        }

        if ($widthMm === null) {
            throw InvalidSaleQuantity::on('width_m', 'Informe a largura.', 'required');
        }
        $this->checkDimension($widthMm, 'width_m', 'Largura');

        if ($variant->minWidthMm !== null && $widthMm < $variant->minWidthMm) {
            throw InvalidSaleQuantity::on('width_m', 'Largura mínima: '.self::meters($variant->minWidthMm).'.', 'min');
        }
        if ($variant->maxWidthMm !== null && $widthMm > $variant->maxWidthMm) {
            $message = 'Largura máxima: '.self::meters($variant->maxWidthMm).'.';
            // RN-QTD-038: no automatic rotation, but suggest swapping when it would fit.
            if ($heightMm !== null && $heightMm <= $variant->maxWidthMm
                && ($variant->minWidthMm === null || $heightMm >= $variant->minWidthMm)
                && ($variant->maxHeightMm === null || $widthMm <= $variant->maxHeightMm)
                && ($variant->minHeightMm === null || $widthMm >= $variant->minHeightMm)) {
                $message .= ' Tente inverter largura e altura.';
            }
            throw InvalidSaleQuantity::on('width_m', $message, 'max');
        }

        return $widthMm;
    }

    private function checkDimension(int $mm, string $field, string $label): void
    {
        if ($mm < self::MIN_DIMENSION_MM || $mm > self::MAX_DIMENSION_MM) {
            throw InvalidSaleQuantity::on($field, "{$label} deve estar entre 0,01 m e 100 m.", 'range');
        }
        if ($mm % 10 !== 0) {
            // RN-QTD-034: 1 cm precision.
            throw InvalidSaleQuantity::on($field, "{$label}: use no máximo 2 casas decimais (centímetros).", 'precision');
        }
    }

    private function checkQuantityRules(VariantData $variant, Quantity $quantity, string $field, string $unit, bool $integerOnly = false): void
    {
        $integer = $integerOnly || ! $variant->saleUnit->allowsFraction();
        $decimals = $integer ? 0 : 2;
        $fmt = static fn (Quantity $q): string => $q->format($decimals).' '.$unit;

        if (! $quantity->isPositive()) {
            throw InvalidSaleQuantity::on($field, 'A quantidade deve ser maior que zero.', 'positive');
        }
        if ($quantity->milli() > self::MAX_QUANTITY_MILLI) {
            throw InvalidSaleQuantity::on($field, 'Quantidade acima do limite permitido.', 'max');
        }
        if ($integer && ! $quantity->isInteger()) {
            throw InvalidSaleQuantity::on($field, 'Quantidade deve ser inteira.', 'integer');
        }
        if ($quantity->lessThan($variant->minQuantity)) {
            throw InvalidSaleQuantity::on($field, 'Quantidade mínima: '.$fmt($variant->minQuantity).'.', 'min');
        }
        if ($variant->maxQuantity !== null && $quantity->greaterThan($variant->maxQuantity)) {
            throw InvalidSaleQuantity::on($field, 'Quantidade máxima: '.$fmt($variant->maxQuantity).'.', 'max');
        }

        $step = $variant->quantityStep;
        if ($step->isPositive() && ! $quantity->isMultipleOf($step)) {
            $suggestions = [];
            $floor = $quantity->floorToMultipleOf($step);
            $ceil = $quantity->ceilToMultipleOf($step);
            if ($floor->greaterThanOrEqual($variant->minQuantity) && $floor->isPositive()) {
                $suggestions[] = $floor;
            }
            if ($variant->maxQuantity === null || $ceil->lessThanOrEqual($variant->maxQuantity)) {
                $suggestions[] = $ceil;
            }
            $text = implode(' ou ', array_map($fmt, $suggestions));
            $message = 'Use múltiplos de '.$fmt($step).'.'
                .($suggestions !== [] ? (count($suggestions) > 1 ? ' Sugestões: ' : ' Sugestão: ').$text.'.' : '');

            throw InvalidSaleQuantity::on($field, $message, 'step', array_map(static fn (Quantity $q) => $q->toNumber(), $suggestions));
        }
    }

    /** RN-LOG-002: ceil(weight_g × billable_milli / 1000); KG without weight uses the quantity itself. */
    public static function lineWeight(VariantData $variant, Quantity $billable): int
    {
        if ($variant->weightGrams === 0 && $variant->saleUnit === SaleUnit::Kg) {
            return $billable->milli();
        }

        return Rounding::ceilDiv(Rounding::multiply($variant->weightGrams, $billable->milli()), Quantity::SCALE);
    }

    /** 1220 → "1,22 m" */
    public static function meters(int $mm): string
    {
        return Quantity::fromMilli($mm)->format(2).' m';
    }
}
