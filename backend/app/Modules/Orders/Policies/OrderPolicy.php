<?php

declare(strict_types=1);

namespace App\Modules\Orders\Policies;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use Illuminate\Auth\Access\Response;

/**
 * Customer access to orders (SECURITY.md §5). Customer routes already resolve
 * the order scoped to the authenticated customer (404 for others); the
 * policy is the second barrier and also denies with 404.
 */
final class OrderPolicy
{
    public function view(Customer $customer, Order $order): Response
    {
        return $order->customer_id === $customer->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function cancel(Customer $customer, Order $order): Response
    {
        return $this->view($customer, $order);
    }

    public function pay(Customer $customer, Order $order): Response
    {
        return $this->view($customer, $order);
    }

    public static function canCancel(Order $order): bool
    {
        return $order->status === OrderStatus::PendingPayment;
    }
}
