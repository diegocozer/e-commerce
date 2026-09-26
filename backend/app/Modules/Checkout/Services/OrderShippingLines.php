<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\Contracts\OrderShippingRequestSource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;

/**
 * BE-CHK-08: logistics of an existing order for the admin shipping simulator
 * (`order_id` mode). Items come from the order snapshot (variant + configuration);
 * weights/packages are the CURRENT catalog data (lines of variants no longer
 * sellable or whose configuration became invalid are skipped). Subtotal = order
 * products subtotal after the coupon discount, as the checkout quoted it.
 */
final class OrderShippingLines implements OrderShippingRequestSource
{
    public function __construct(private readonly CartService $carts) {}

    public function forOrder(int $orderId): ?array
    {
        /** @var Order|null $order */
        $order = Order::query()->with('items')->find($orderId);
        if ($order === null) {
            return null;
        }

        $items = $order->items->map(static fn (OrderItem $i): array => [
            'variant_id' => (int) $i->variant_id,
            'quantity' => $i->width_mm !== null ? null : $i->quantity,
            'width_mm' => $i->width_mm !== null ? (int) $i->width_mm : null,
            'height_mm' => $i->height_mm !== null ? (int) $i->height_mm : null,
            'pieces' => $i->pieces !== null ? (int) $i->pieces : null,
        ])->all();

        return [
            'lines' => $this->carts->shippingLinesForItems(array_values($items), (int) $order->customer_id)['lines'],
            'subtotal_cents' => max(0, (int) $order->subtotal_cents - (int) $order->discount_cents),
            'postal_code' => (string) ($order->shipping_postal_code ?? ''),
        ];
    }
}
