<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources\Customer;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Support\OrderPresenter as P;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** OrderDetail (API.md §2.8). Needs items, statusHistory and payments loaded. @mixin Order */
class OrderDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return self::detail($order);
    }

    /** @return array<string, mixed> */
    public static function detail(Order $order): array
    {
        return [
            ...OrderSummaryResource::summary($order),
            'items_count' => $order->items->count(),
            'paid_at' => P::iso($order->paid_at),
            'cancelled_at' => P::iso($order->cancelled_at),
            'cancel_reason_code' => $order->cancel_reason_code?->value,
            'cancel_reason_label' => P::cancelReasonLabel($order->cancel_reason_code),
            'cancellation_request' => $order->cancellation_requested_at === null ? null : [
                'requested_at' => P::iso($order->cancellation_requested_at),
                'reason' => $order->cancellation_request_reason,
            ],
            'items' => $order->items->map(static fn (OrderItem $i): array => P::item($i))->values()->all(),
            'totals' => P::totals($order),
            'coupon_code' => $order->coupon_code,
            'total_weight_grams' => $order->total_weight_grams,
            'billing' => [
                'customer_type' => $order->customer_type->value,
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'document' => $order->customer_document,
                'phone' => $order->customer_phone,
                'company_name' => $order->customer_company_name,
                'state_registration' => $order->customer_state_registration,
            ],
            'shipping' => P::shipping($order),
            'payment' => P::payment(P::latestPayment($order)),
            'timeline' => $order->statusHistory->map(static fn (OrderStatusHistory $h): array => [
                'status' => $h->to_status->value,
                'status_label' => P::timelineLabel($h->to_status, $h->from_status === OrderStatus::Cancelled),
                'occurred_at' => P::iso($h->created_at),
                // Public notes only: tracking (shipped) and cancellation reason.
                'note' => in_array($h->to_status, [OrderStatus::Shipped, OrderStatus::Cancelled], true) ? $h->note : null,
            ])->values()->all(),
            'notes' => $order->notes,
        ];
    }
}
