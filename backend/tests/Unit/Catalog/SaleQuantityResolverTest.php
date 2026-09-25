<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Modules\Catalog\Services\DefaultSaleQuantityResolver;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RN-QTD worked examples X1–X11 (BUSINESS_RULES §4.2.5) + ADR-019. */
final class SaleQuantityResolverTest extends TestCase
{
    private DefaultSaleQuantityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DefaultSaleQuantityResolver;
    }

    /** @param  array<string, mixed>  $o */
    private static function variant(SaleUnit $unit, array $o = []): VariantData
    {
        return new VariantData(
            id: 1, productId: 1, productSlug: 'p', productName: 'P', sku: 'SKU-1', name: 'Padrão', saleUnit: $unit,
            isActive: true, productIsActive: true, pickupOnly: false, brandId: null, primaryCategoryId: 1, categoryIdsWithAncestors: [1],
            minQuantity: Quantity::fromString($o['min'] ?? '1'),
            maxQuantity: isset($o['max']) ? Quantity::fromString($o['max']) : null,
            quantityStep: Quantity::fromString($o['step'] ?? '1'),
            fixedWidthMm: $o['fixed'] ?? null,
            minWidthMm: $o['minW'] ?? null, maxWidthMm: $o['maxW'] ?? null,
            minHeightMm: $o['minH'] ?? null, maxHeightMm: $o['maxH'] ?? null,
            minBillableArea: isset($o['minArea']) ? Quantity::fromString($o['minArea']) : null,
            weightGrams: $o['weight'] ?? 100, package: null, unitsPerPackage: null, unitsPerBox: null, rollLengthM: null, imageUrl: null,
        );
    }

    private static function vinyl(): VariantData
    {
        return self::variant(SaleUnit::LinearMeter, ['min' => '1', 'step' => '0.100', 'max' => '50', 'fixed' => 1220, 'weight' => 250]);
    }

    private static function lona(?string $minArea = '0.500'): VariantData
    {
        return self::variant(SaleUnit::SquareMeter, ['minW' => 300, 'maxW' => 3200, 'minH' => 300, 'maxH' => 50000, 'minArea' => $minArea, 'weight' => 460]);
    }

    /** @return iterable<string, array{VariantData, SaleInput, int, int, int}> */
    public static function workedExamples(): iterable
    {
        yield 'X1 vinil 5 m' => [self::vinyl(), SaleInput::quantity(Quantity::fromString('5')), 1590, 5000, 7950];
        yield 'X2 vinil 5,35 m (half up, step 0,01)' => [self::variant(SaleUnit::LinearMeter, ['step' => '0.010']), SaleInput::quantity(Quantity::fromString('5.35')), 1590, 5350, 8507];
        yield 'X3 lona 1,20 x 2,50 x 1' => [self::lona(), SaleInput::dimensions(1200, 2500, 1), 3000, 3000, 9000];
        yield 'X4 lona 1,20 x 2,50 x 3' => [self::lona(), SaleInput::dimensions(1200, 2500, 3), 3000, 9000, 27000];
        yield 'X5 lona 0,40 x 0,50 min 0,50' => [self::lona(), SaleInput::dimensions(400, 500, 1), 3000, 500, 1500];
        yield 'X6 backlight fixed 3,20 x 2,00' => [self::variant(SaleUnit::SquareMeter, ['fixed' => 3200, 'minH' => 300, 'maxH' => 30000]), SaleInput::dimensions(null, 2000, 1), 3200, 6400, 20480];
        yield 'X7 ilhós 100 un' => [self::variant(SaleUnit::Unit), SaleInput::quantity(Quantity::fromString('100')), 50, 100000, 5000];
        yield 'X8 bobina 2 rolos' => [self::variant(SaleUnit::Roll), SaleInput::quantity(Quantity::fromString('2')), 35000, 2000, 70000];
        yield 'X9 caixa 3' => [self::variant(SaleUnit::Box), SaleInput::quantity(Quantity::fromString('3')), 2490, 3000, 7470];
        yield 'X10 pó 1,5 kg' => [self::variant(SaleUnit::Kg, ['min' => '0.5', 'step' => '0.5']), SaleInput::quantity(Quantity::fromString('1.5')), 8990, 1500, 13485];
    }

    #[DataProvider('workedExamples')]
    public function test_worked_examples(VariantData $variant, SaleInput $input, int $unitCents, int $billableMilli, int $totalCents): void
    {
        $result = $this->resolver->resolve($variant, $input);

        self::assertSame($billableMilli, $result->billable->milli());
        self::assertSame($totalCents, Money::ofCents($unitCents)->multiplyByQuantity($result->billable)->cents());
    }

    public function test_x11_off_step_is_rejected_with_suggestions(): void
    {
        try {
            $this->resolver->resolve(self::vinyl(), SaleInput::quantity(Quantity::fromString('5.05')));
            self::fail('Expected InvalidSaleQuantity');
        } catch (InvalidSaleQuantity $e) {
            self::assertSame('quantity', $e->field);
            self::assertSame('Use múltiplos de 0,10 m. Sugestões: 5,00 m ou 5,10 m.', $e->message());
            self::assertSame([5, 5.1], $e->suggestions);
            $body = $e->render()->getData(true);
            self::assertSame([5, 5.1], $body['details']['quantity']['suggestions']);
        }
    }

    public function test_minimum_area_applies_per_piece_and_stock_uses_real_area(): void
    {
        $r = $this->resolver->resolve(self::lona('1.000'), SaleInput::dimensions(400, 500, 3));

        self::assertTrue($r->minimumAreaApplied);
        self::assertSame(200, $r->pieceArea?->milli());
        self::assertSame(3000, $r->billable->milli());
        self::assertSame(600, $r->stock->milli());
        self::assertSame(1380, $r->weightGrams); // ceil(460 × 3000 / 1000)
    }

    public function test_weight_is_ceiled_and_kg_without_weight_uses_quantity(): void
    {
        self::assertSame(1250, $this->resolver->resolve(self::vinyl(), SaleInput::quantity(Quantity::fromString('5')))->weightGrams);
        $kg = self::variant(SaleUnit::Kg, ['min' => '0.5', 'step' => '0.5', 'weight' => 0]);
        self::assertSame(1500, $this->resolver->resolve($kg, SaleInput::quantity(Quantity::fromString('1.5')))->weightGrams);
    }

    /** @return iterable<string, array{VariantData, SaleInput, string, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'below min' => [self::vinyl(), SaleInput::quantity(Quantity::fromString('0.5')), 'quantity', 'Quantidade mínima: 1,00 m.'];
        yield 'above max' => [self::vinyl(), SaleInput::quantity(Quantity::fromString('60')), 'quantity', 'Quantidade máxima: 50,00 m.'];
        yield 'integer unit' => [self::variant(SaleUnit::Unit), SaleInput::quantity(Quantity::fromString('2.5')), 'quantity', 'Quantidade deve ser inteira.'];
        yield 'ilhós step 50' => [self::variant(SaleUnit::Unit, ['min' => '50', 'step' => '50']), SaleInput::quantity(Quantity::fromString('120')), 'quantity', 'Use múltiplos de 50 un. Sugestões: 100 un ou 150 un.'];
        yield 'zero' => [self::variant(SaleUnit::Unit), SaleInput::quantity(Quantity::zero()), 'quantity', 'A quantidade deve ser maior que zero.'];
        yield 'missing quantity' => [self::variant(SaleUnit::Unit), new SaleInput(null, null, null, null), 'quantity', 'Informe a quantidade.'];
        yield 'width below min' => [self::lona(), SaleInput::dimensions(200, 1000), 'width_m', 'Largura mínima: 0,30 m.'];
        yield 'height above max' => [self::lona(), SaleInput::dimensions(1000, 60000), 'height_m', 'Altura máxima: 50,00 m.'];
        yield 'mm precision' => [self::lona(), SaleInput::dimensions(1205, 1000), 'width_m', 'Largura: use no máximo 2 casas decimais (centímetros).'];
        yield 'swap hint' => [self::lona(), SaleInput::dimensions(4000, 1500), 'width_m', 'Largura máxima: 3,20 m. Tente inverter largura e altura.'];
        yield 'fixed width mismatch' => [self::variant(SaleUnit::SquareMeter, ['fixed' => 3200]), SaleInput::dimensions(1000, 1000), 'width_m', 'A largura deste produto é fixa em 3,20 m.'];
        yield 'pieces above 1000' => [self::lona(), SaleInput::dimensions(1000, 1000, 1001), 'pieces', 'Informe entre 1 e 1.000 peças.'];
        yield 'pieces above product max' => [self::variant(SaleUnit::SquareMeter, ['max' => '10', 'minW' => 300, 'maxW' => 3200]), SaleInput::dimensions(1000, 1000, 11), 'pieces', 'Quantidade máxima: 10 peças.'];
        yield 'missing height' => [self::lona(), new SaleInput(null, 1000, null, 1), 'height_m', 'Informe a altura.'];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs(VariantData $variant, SaleInput $input, string $field, string $message): void
    {
        try {
            $this->resolver->resolve($variant, $input);
            self::fail('Expected InvalidSaleQuantity');
        } catch (InvalidSaleQuantity $e) {
            self::assertSame($field, $e->field);
            self::assertSame($message, $e->message());
        }
    }

    public function test_with_field_renames_error_key(): void
    {
        $e = InvalidSaleQuantity::on('quantity', 'x', 'step', [1])->withField('items.2.quantity');
        self::assertSame(['items.2.quantity' => ['x']], $e->errors());
    }
}
