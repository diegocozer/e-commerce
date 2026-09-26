<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Cart\Contracts\ReorderOffers;
use App\Modules\Checkout\Services\OrderDetailPresenter;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;

/**
 * GET /me/reorder-suggestions (API.md §3.D, ADR-035): one suggestion per variant
 * bought in the customer's recent PAID orders (latest configuration), with the
 * CURRENT price for that customer; inactive or out-of-stock variants are left out.
 */
final class ListReorderSuggestions
{
    /** Recent paid order lines scanned before de-duplicating by variant. */
    private const int SCAN_LIMIT = 200;

    public function __construct(private readonly ReorderOffers $offers) {}

    /** @return list<array<string, mixed>> ReorderSuggestion[] */
    public function execute(int $customerId, int $limit): array
    {
        /** @var list<OrderItem> $rows */
        $rows = OrderItem::query()
            ->select('order_items.*', 'orders.placed_at as order_placed_at')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customerId)
            ->whereNotNull('orders.paid_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->orderByDesc('orders.placed_at')->orderByDesc('orders.id')->orderBy('order_items.id')
            ->limit(self::SCAN_LIMIT)
            ->get()->all();

        /** @var array<int, OrderItem> $latest */
        $latest = [];
        foreach ($rows as $row) {
            $latest[(int) $row->variant_id] ??= $row;
        }

        $offers = $this->offers->offers(array_map(static fn (OrderItem $i) => $i->billable_quantity, $latest), $customerId);

        $out = [];
        foreach ($offers as $variantId => $offer) {
            $item = $latest[$variantId];
            $out[] = [
                'variant_id' => $variantId,
                'product' => $offer['product'],
                'variant' => $offer['variant'],
                'last_configuration' => OrderDetailPresenter::configuration($item),
                'last_configuration_label' => OrderDetailPresenter::configurationLabel($item),
                'last_ordered_at' => CarbonImmutable::parse((string) $item->getAttribute('order_placed_at'))->utc()->format('Y-m-d\TH:i:s\Z'),
                'current_unit_price_cents' => $offer['current_unit_price_cents'],
                'price_source' => $offer['price_source'],
                'availability' => ['status' => $offer['availability_status']],
            ];
            if (count($out) === $limit) {
                break;
            }
        }

        return $out;
    }
}
