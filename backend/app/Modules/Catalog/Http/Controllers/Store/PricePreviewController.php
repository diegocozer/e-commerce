<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Modules\Catalog\Http\Requests\Store\PricePreviewRequest;
use App\Modules\Catalog\Services\DefaultSaleQuantityResolver;
use App\Modules\Catalog\Services\StorefrontPresenter;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\Contracts\PriceTierTable;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * POST /products/{slug}/price-preview (API.md §3.A / §2.4). No side effects;
 * the tier considers only this line; insufficient stock is not an error.
 */
final class PricePreviewController
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly SaleQuantityResolver $quantities,
        private readonly PriceResolver $prices,
        private readonly PriceTierTable $tiers,
        private readonly InventoryService $inventory,
        private readonly StorefrontPresenter $presenter,
    ) {}

    public function store(PricePreviewRequest $request, string $slug): JsonResponse
    {
        $product = ProductController::findVisible($slug);
        $data = $request->validated();

        $variant = $this->catalog->variantBySlug($slug, (int) $data['variant_id']);
        if ($variant === null || ! $variant->isSellable()) {
            throw InvalidSaleQuantity::on('variant_id', 'Variante indisponível para este produto.', 'variant');
        }

        $isArea = $variant->saleUnit->usesDimensions();
        foreach ($isArea ? ['quantity'] : ['width_m', 'height_m', 'pieces'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                throw InvalidSaleQuantity::on($field, 'O campo não se aplica a esta unidade de venda.', 'prohibited');
            }
        }

        $input = $isArea
            ? new SaleInput(null, self::mm($data['width_m'] ?? null), self::mm($data['height_m'] ?? null), isset($data['pieces']) ? (int) $data['pieces'] : null)
            : new SaleInput(isset($data['quantity']) ? Quantity::fromString((string) $data['quantity']) : null, null, null, null);
        if ($isArea && ! isset($data['pieces'])) {
            throw InvalidSaleQuantity::on('pieces', 'Informe a quantidade de peças.', 'required');
        }

        $billable = $this->quantities->resolve($variant, $input);
        $subject = $this->catalog->pricingSubject($variant->id);
        $ctx = new PriceContext($subject, $billable->billable, null, ProductController::customerId(), CarbonImmutable::now());
        $quote = $this->prices->resolve($ctx);

        $tierRows = $this->presenter->tierDisplay($this->tiers->tiersFor($ctx, $this->presenter->minimumBillable($product)), $product);
        $applied = null;
        $next = null;
        foreach ($tierRows as $row) {
            $min = Quantity::fromNumeric($row['min_quantity']);
            if ($min->lessThanOrEqual($billable->billable)) {
                $applied = $row;
            } elseif ($next === null) {
                $next = [...$row, 'missing_quantity' => $min->subtract($billable->billable)->toNumber()];
            }
        }

        $available = $this->inventory->availability([$variant->id])[$variant->id];
        $sufficient = $available->greaterThanOrEqual($billable->stock);
        $m = static fn (?int $mm) => $mm !== null ? Quantity::fromMilli($mm)->toNumber() : null;

        return new JsonResponse(['data' => [
            'variant_id' => $variant->id,
            'sale_unit' => $variant->saleUnit->value,
            'configuration' => [
                'quantity' => $isArea ? null : $billable->billable->toNumber(),
                'width_m' => $isArea ? $m($billable->widthMm) : null,
                'height_m' => $isArea ? $m($billable->heightMm) : null,
                'pieces' => $isArea ? $billable->pieces : null,
            ],
            'configuration_label' => $isArea
                ? sprintf('%s m × %s m × %d %s', Quantity::fromMilli((int) $billable->widthMm)->format(2), Quantity::fromMilli((int) $billable->heightMm)->format(2), $billable->pieces, $billable->pieces === 1 ? 'peça' : 'peças')
                : self::quantityLabel($variant->saleUnit, $billable->billable),
            'billable_quantity' => $billable->billable->toNumber(),
            'stock_quantity' => $billable->stock->toNumber(),
            'piece_area_m2' => $billable->pieceArea?->toNumber(),
            'area_m2' => $isArea ? $billable->stock->toNumber() : null,
            'min_area_applied' => $billable->minimumAreaApplied,
            'unit_price_cents' => $quote->unitPrice->cents(),
            'base_unit_price_cents' => $quote->baseUnitPrice->cents(),
            'compare_at_cents' => $quote->compareAt()?->cents(),
            'price_source' => $quote->source->value,
            'price_source_label' => $quote->sourceLabel,
            'line_total_cents' => $quote->lineTotal->cents(),
            'weight_grams' => $billable->weightGrams ?: DefaultSaleQuantityResolver::lineWeight($variant, $billable->billable),
            'applied_tier' => $applied,
            'next_tier' => $next,
            'stock' => ['sufficient' => $sufficient, 'available_quantity' => $sufficient ? null : Quantity::max($available, Quantity::zero())->toNumber()],
        ]]);
    }

    private static function mm(?string $meters): ?int
    {
        return $meters === null ? null : Quantity::fromString($meters)->milli(); // metres × 1000 = mm
    }

    private static function quantityLabel(SaleUnit $unit, Quantity $q): string
    {
        $n = str_replace('.', ',', $q->toTrimmedString());
        $plural = $q->milli() !== 1000;

        return $n.' '.match ($unit) {
            SaleUnit::Roll => $plural ? 'rolos' : 'rolo',
            SaleUnit::Box => $plural ? 'caixas' : 'caixa',
            SaleUnit::Unit => $plural ? 'unidades' : 'unidade',
            default => $unit->abbreviation(),
        };
    }
}
