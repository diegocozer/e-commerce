<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Admin;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

/** POST /admin/orders/{id}/cancel: `confirm_refund` accepted for paid/processing, prohibited otherwise. */
final class AdminCancelOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Order|null $order */
        $order = $this->route('order');
        $paid = $order instanceof Order && in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true);

        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm_refund' => $paid ? ['accepted'] : ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
