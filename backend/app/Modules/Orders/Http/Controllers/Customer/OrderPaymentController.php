<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Customer;

use App\Modules\Orders\Actions\RetryOrderPayment;
use App\Modules\Orders\Http\Requests\Customer\RetryPaymentRequest;
use App\Modules\Orders\Http\Resources\Customer\OrderPaymentResource;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\JsonResponse;

/** POST /me/orders/{uuid}/payment — generate the PIX again (API.md §3.D). */
final class OrderPaymentController
{
    use ResolvesCustomerOrder;

    public function store(RetryPaymentRequest $request, string $uuid, RetryOrderPayment $action): JsonResponse
    {
        $order = $this->customerOrder($request, $uuid);
        $result = $action->execute($order);

        return (new OrderPaymentResource(Payment::query()->findOrFail($result['payment']->id)))
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }
}
