<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources\Admin;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderPresenter as P;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** AdminOrderListItem (API.md §2.14). Needs `customer` (+company) and `items_count`. @mixin Order */
class AdminOrderListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;

        return [
            'id' => $o->id,
            'uuid' => $o->uuid,
            'number' => $o->number,
            'status' => $o->status->value,
            'payment_status' => $o->payment_status->value,
            'payment_method' => $o->payment_method->value,
            'customer' => [
                'id' => $o->customer_id,
                'name' => $o->customer_name,
                'type' => $o->customer_type->value,
                'company_name' => $o->customer_company_name,
            ],
            'items_count' => (int) ($o->items_count ?? 0),
            'total_cents' => $o->total_cents,
            'shipping_method_name' => $o->shipping_method_name,
            'shipping_method_type' => $o->shipping_method_type,
            'shipping_city' => $o->shipping_city,
            'shipping_state' => $o->shipping_state,
            'placed_at' => P::iso($o->placed_at),
            'paid_at' => P::iso($o->paid_at),
            'expires_at' => P::iso($o->expires_at),
            'has_cancellation_request' => $o->cancellation_requested_at !== null,
        ];
    }
}
