<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Admin;

use App\Modules\Cart\Contracts\OrderShippingRequestSource;
use App\Modules\Cart\Http\Requests\Admin\ShippingSimulationRequest;
use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\DTOs\SaleInput;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Modules\Pricing\Contracts\PriceResolver;
use App\Modules\Pricing\DTOs\PriceContext;
use App\Modules\Shipping\Contracts\ShippingEngine;
use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\DTOs\CartLineLogisticsInput;
use App\Modules\Shipping\DTOs\CartLogistics;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\DTOs\UnavailableMethod;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Admin shipping simulator (SHIPPING.md §10.1). Lives in Cart because the `items`
 * mode needs Catalog + Pricing (API.md §5.2 note ²). Never persists.
 */
final class ShippingSimulatorController
{
    public function __construct(
        private readonly ShippingEngine $engine,
        private readonly ShippingRequestFactory $requests,
    ) {}

    public function store(ShippingSimulationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $coupon = (bool) ($data['coupon_free_shipping'] ?? false);
        $subtotal = isset($data['subtotal_cents']) ? (int) $data['subtotal_cents'] : null;

        if (isset($data['logistics_override'])) {
            $base = $this->requests->fromLines([], (string) $data['postal_code'], Money::ofCents($subtotal ?? 0), $coupon);
            $o = $data['logistics_override'];
            $shippingRequest = new ShippingRequest(
                $base->destination,
                CartLogistics::fromTotals((int) $o['total_weight_grams'], (int) $o['total_volume_cm3'], (int) round(((float) $o['largest_dimension_cm']) * 10)),
                $base->subtotalCents,
                $coupon,
            );
        } elseif (isset($data['order_id'])) {
            $source = app()->bound(OrderShippingRequestSource::class) ? app(OrderShippingRequestSource::class) : null;
            $order = $source?->forOrder((int) $data['order_id']);
            if ($order === null) {
                throw ValidationException::withMessages(['order_id' => [$source === null ? 'Indisponível.' : 'Pedido não encontrado.']]);
            }
            $shippingRequest = $this->requests->fromLines($order['lines'], (string) $data['postal_code'], Money::ofCents($subtotal ?? $order['subtotal_cents']), $coupon);
        } else {
            [$lines, $itemsSubtotal] = $this->linesFromItems($data['items']);
            $shippingRequest = $this->requests->fromLines($lines, (string) $data['postal_code'], Money::ofCents($subtotal ?? $itemsSubtotal), $coupon);
        }

        $at = isset($data['at']) ? CarbonImmutable::parse((string) $data['at']) : null;
        $onlyMethods = isset($data['method_ids']) ? array_map('intval', $data['method_ids']) : null;
        $result = $this->engine->evaluate($shippingRequest, true, $onlyMethods, $at);
        $logistics = $shippingRequest->logistics;
        $trace = $result->trace?->toArray() ?? ['zones_matched' => [], 'methods' => []];

        return new JsonResponse(['data' => [
            'destination' => $result->destination->toArray(),
            'subtotal_cents' => $shippingRequest->subtotalCents,
            'coupon_free_shipping' => $coupon,
            'logistics' => [
                'total_weight_grams' => $logistics->totalWeightGrams,
                'total_volume_cm3' => $logistics->totalVolumeCm3,
                'cubic_weight_grams_6000' => $logistics->cubicWeightGrams(6000),
                'volumes_count' => $logistics->volumesCount,
                'largest_dimension_cm' => $logistics->largestDimensionMm / 10,
                'has_pickup_only_items' => $logistics->hasPickupOnlyItems,
                'missing_data' => $logistics->missingData,
                'variants_missing_data' => $logistics->variantsMissingData,
            ],
            'zones_matched' => $trace['zones_matched'],
            'methods' => $trace['methods'],
            'options' => array_map(static fn (ShippingOption $o): array => $o->toPublicArray() + ['method_id' => $o->methodId, 'rule_id' => $o->ruleId], $result->options),
            'unavailable' => array_map(static fn (UnavailableMethod $u): array => $u->toArray(), $result->unavailable),
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<CartLineLogisticsInput>, 1: int}
     */
    private function linesFromItems(array $items): array
    {
        $catalog = app(CatalogQuery::class);
        $quantities = app(SaleQuantityResolver::class);
        $variants = $catalog->variants(array_values(array_unique(array_map(static fn (array $i): int => (int) $i['variant_id'], $items))));
        $subjects = $catalog->pricingSubjects(array_keys($variants));

        $lines = [];
        $contexts = [];
        foreach ($items as $i => $item) {
            $variant = $variants[(int) $item['variant_id']] ?? null;
            if ($variant === null) {
                throw ValidationException::withMessages(["items.{$i}.variant_id" => ['Variante inexistente.']]);
            }
            $input = $variant->saleUnit === SaleUnit::SquareMeter
                ? new SaleInput(null, isset($item['width_m']) ? self::mm($item['width_m']) : null, isset($item['height_m']) ? self::mm($item['height_m']) : null, (int) ($item['pieces'] ?? 1))
                : SaleInput::quantity(Quantity::fromNumeric($item['quantity'] ?? 1));

            try {
                $billable = $quantities->resolve($variant, $input);
            } catch (InvalidSaleQuantity $e) {
                throw ValidationException::withMessages(["items.{$i}.{$e->field}" => [$e->message()]]);
            }

            $lines[] = new CartLineLogisticsInput(
                $variant->id, $variant->saleUnit, $billable->billable, $billable->widthMm, $billable->heightMm, $billable->pieces,
                $variant->weightGrams, $variant->package, $variant->unitsPerPackage, $variant->fixedWidthMm, $variant->pickupOnly, $variant->sku,
            );
            $contexts[] = new PriceContext($subjects[$variant->id], $billable->billable, null, null, CarbonImmutable::now());
        }

        $subtotal = 0;
        foreach (app(PriceResolver::class)->resolveMany($contexts) as $quote) {
            $subtotal += $quote->lineTotal->cents();
        }

        return [$lines, $subtotal];
    }

    private static function mm(mixed $meters): int
    {
        return Quantity::fromNumeric($meters)->milli(); // metres with 3 decimals ⇒ millimetres
    }
}
