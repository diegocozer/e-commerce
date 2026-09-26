<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Customer;

use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** IDOR guard (SECURITY.md §5): orders are always fetched from the authenticated customer; others → 404. */
trait ResolvesCustomerOrder
{
    /** @param list<string> $with */
    protected function customerOrder(Request $request, string $uuid, array $with = []): Order
    {
        $customer = $request->user('customer');
        $order = Order::query()
            ->where('customer_id', (int) $customer?->getAuthIdentifier())
            ->where('uuid', $uuid)
            ->with($with)
            ->firstOrFail();
        Gate::forUser($customer)->authorize('view', $order);

        return $order;
    }

    /** @return list<string> */
    protected static function detailRelations(): array
    {
        return ['items', 'statusHistory', 'payments'];
    }
}
