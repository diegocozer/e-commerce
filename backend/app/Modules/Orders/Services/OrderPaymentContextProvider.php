<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Contracts\PaymentOrderContextProvider;
use App\Modules\Payments\DTOs\PayerData;
use App\Modules\Payments\DTOs\PaymentOrderContext;

/** Order number + payer (from the order snapshot) for Payments::initiate (inversion). */
final class OrderPaymentContextProvider implements PaymentOrderContextProvider
{
    public function forOrder(int $orderId): ?PaymentOrderContext
    {
        $order = Order::query()->find($orderId, ['id', 'number', 'customer_name', 'customer_email', 'customer_document', 'customer_company_name']);
        if ($order === null) {
            return null;
        }

        return new PaymentOrderContext($order->id, $order->number, new PayerData(
            $order->customer_company_name ?? $order->customer_name,
            $order->customer_email,
            $order->customer_document,
        ));
    }
}
