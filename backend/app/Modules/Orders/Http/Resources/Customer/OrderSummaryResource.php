<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources\Customer;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderPresenter as P;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** OrderSummary (API.md §2.8). Needs `items_count` and `payments` loaded. @mixin Order */
class OrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return self::summary($order);
    }

    /** @return array<string, mixed> */
    public static function summary(Order $order): array
    {
        return [
            'uuid' => $order->uuid,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'payment_status' => $order->payment_status->value,
            'payment_method' => $order->payment_method->value,
            'placed_at' => P::iso($order->placed_at),
            'expires_at' => P::iso($order->expires_at),
            'items_count' => (int) ($order->items_count ?? ($order->relationLoaded('items') ? $order->items->count() : 0)),
            'total_cents' => $order->total_cents,
            'shipping_method_name' => $order->shipping_method_name,
            'shipping_method_type' => $order->shipping_method_type,
            'tracking_code' => $order->tracking_code,
            'allowed_actions' => P::allowedActions($order),
        ];
    }
}
