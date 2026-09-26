<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources\Customer;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderPresenter as P;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** OrderStatusPoll (API.md §2.8). @mixin Order */
class OrderStatusPollResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $payment = P::latestPayment($order);

        return [
            'uuid' => $order->uuid,
            'number' => $order->number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'expires_at' => P::iso($order->expires_at),
            'paid_at' => P::iso($order->paid_at),
            'payment' => $payment === null ? null : [
                'uuid' => $payment->uuid,
                'status' => $payment->status->value,
                'expires_at' => P::iso($payment->expires_at),
                'has_pix' => $payment->pix_copy_paste !== null,
            ],
            'updated_at' => P::iso($order->updated_at),
        ];
    }
}
