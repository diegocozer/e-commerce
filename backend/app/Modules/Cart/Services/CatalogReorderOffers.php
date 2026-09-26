<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Contracts\ReorderOffers;
use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Shared\Domain\Quantity;
use Carbon\CarbonImmutable;

final class CatalogReorderOffers implements ReorderOffers
{
    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly InventoryService $inventory,
        private readonly PriceResolver $prices,
    ) {}

    public function offers(array $billableByVariant, ?int $customerId): array
    {
        if ($billableByVariant === []) {
            return [];
        }
        $ids = array_keys($billableByVariant);
        $variants = $this->catalog->variants($ids);
        $available = $this->inventory->availability($ids);

        $sellable = [];
        foreach ($ids as $id) {
            $variant = $variants[$id] ?? null;
            if ($variant === null || ! $variant->isSellable()) {
                continue;
            }
            $status = $variant->availabilityStatus($available[$id] ?? Quantity::zero());
            if ($status !== 'out_of_stock') {
                $sellable[$id] = [$variant, $status];
            }
        }
        if ($sellable === []) {
            return [];
        }

        $subjects = $this->catalog->pricingSubjects(array_keys($sellable));
        $now = CarbonImmutable::now();
        $quotes = $this->prices->resolveMany(array_map(
            static fn (int $id): PriceContext => new PriceContext($subjects[$id], $billableByVariant[$id], null, $customerId, $now),
            array_keys($sellable),
        ));

        $out = [];
        $i = 0;
        foreach ($sellable as $id => [$variant, $status]) {
            $quote = $quotes[$i++];
            $out[$id] = [
                'product' => [
                    'id' => $variant->productId,
                    'slug' => $variant->productSlug,
                    'name' => $variant->productName,
                    'url_path' => $variant->urlPath(),
                    'image' => $variant->image,
                    'sale_unit' => $variant->saleUnit->value,
                    'sale_unit_abbr' => $variant->saleUnit->abbreviation(),
                ],
                'variant' => ['id' => $id, 'sku' => $variant->sku, 'name' => $variant->name],
                'current_unit_price_cents' => $quote->unitPrice->cents(),
                'price_source' => $quote->source->value,
                'availability_status' => $status,
            ];
        }

        return $out;
    }
}
