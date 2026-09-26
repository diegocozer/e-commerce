<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\DTOs\CartLine;
use App\Modules\Checkout\Exceptions\CheckoutFailed;
use App\Modules\Orders\Contracts\OrderPlacement;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;

/**
 * POST /me/orders/{uuid}/reorder (API.md §3.D, RN-PED-040…045): re-adds every line
 * of an order (any status) to the customer's active cart with the CURRENT rules,
 * sellability, stock and prices. Always 200 with a ReorderReport; another
 * customer's order → 404 not_found (never revealed).
 */
final class ReorderOrder
{
    public function __construct(
        private readonly OrderPlacement $orders,
        private readonly CartService $carts,
        private readonly CartPresenter $presenter,
    ) {}

    /** @return array<string, mixed> ReorderReport */
    public function execute(int $customerId, string $orderUuid): array
    {
        $orderId = Order::query()->where('uuid', $orderUuid)->where('customer_id', $customerId)->value('id');
        $order = $orderId !== null ? $this->orders->find((int) $orderId) : null;
        if ($order === null || $order->customerId !== $customerId) {
            throw CheckoutFailed::make('not_found', 'Recurso não encontrado.', status: 404);
        }

        /** @var list<OrderItem> $items */
        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->all();
        $outcome = $this->carts->addReorderLines($customerId, array_map(static fn (OrderItem $i): array => [
            'variant_id' => (int) $i->variant_id,
            'quantity' => $i->width_mm !== null ? null : $i->quantity,
            'width_mm' => $i->width_mm !== null ? (int) $i->width_mm : null,
            'height_mm' => $i->height_mm !== null ? (int) $i->height_mm : null,
            'pieces' => $i->pieces !== null ? (int) $i->pieces : null,
        ], $items));

        $snapshot = $this->carts->snapshot($outcome['cart_id'], $customerId);
        $cart = $this->presenter->cart($outcome['cart_id'], $customerId);

        $report = [];
        $counts = ['added' => 0, 'adjusted' => 0, 'skipped' => 0];
        foreach ($items as $index => $item) {
            $r = $outcome['results'][$index];
            $variant = $r['variant'];
            $current = $this->currentPrice($snapshot->lines, $item);
            $name = $variant->productName ?? $item->product_name;
            $abbr = $item->sale_unit->abbreviation();
            $added = $r['added'];
            $addedQty = $added !== null ? ($added->quantity ?? Quantity::ofUnits((int) $added->pieces)) : null;

            $message = match ($r['result']) {
                'added' => $current !== null && $current !== $item->unit_price_cents
                    ? 'Adicionado. Preço atual '.Money::ofCents($current)->format().' (no pedido anterior: '.Money::ofCents($item->unit_price_cents)->format().').'
                    : 'Adicionado ao carrinho.',
                'adjusted' => 'Estoque insuficiente: adicionamos '.$addedQty?->format().' '.($added?->width_mm !== null ? ((int) $added->pieces === 1 ? 'peça' : 'peças') : $abbr).'.',
                'invalid_rules' => ($r['message'] ?? 'As regras de venda deste produto mudaram.').' Confira o produto para ajustar.',
                default => $r['message'] ?? 'Indisponível: '.$name.'.',
            };
            match ($r['result']) {
                'added' => $counts['added']++,
                'adjusted' => $counts['adjusted']++,
                default => $counts['skipped']++,
            };

            $report[] = [
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'result' => $r['result'],
                'message' => $message,
                'requested' => $this->presenter->configurationOf($item->width_mm !== null ? null : $item->quantity, $item->width_mm, $item->height_mm, $item->pieces),
                'added' => $added !== null ? $this->presenter->configurationOf($added->quantity, $added->width_mm, $added->height_mm, $added->pieces) : null,
                'previous_unit_price_cents' => (int) $item->unit_price_cents,
                'current_unit_price_cents' => $current,
                'product_url_path' => $variant !== null && $variant->isSellable() ? $variant->urlPath() : null,
            ];
        }

        return [
            'cart' => $cart,
            'summary' => [
                'total_items' => count($items),
                'added_items' => $counts['added'] + $counts['adjusted'],
                'adjusted_items' => $counts['adjusted'],
                'skipped_items' => $counts['skipped'],
            ],
            'items' => $report,
        ];
    }

    /** @param list<CartLine> $lines */
    private function currentPrice(array $lines, OrderItem $item): ?int
    {
        foreach ($lines as $line) {
            if ($line->variantId === (int) $item->variant_id && $line->input->widthMm === $item->width_mm
                && $line->input->heightMm === $item->height_mm && $line->price !== null) {
                return $line->price->unitPrice->cents();
            }
        }

        return null;
    }
}
