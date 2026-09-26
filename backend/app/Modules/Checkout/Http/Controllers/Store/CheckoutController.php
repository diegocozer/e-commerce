<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Http\Controllers\Store;

use App\Modules\Checkout\Actions\PlaceCheckout;
use App\Modules\Checkout\Actions\PreviewCheckout;
use App\Modules\Checkout\DTOs\CheckoutData;
use App\Modules\Checkout\Http\Requests\Store\CheckoutPreviewRequest;
use App\Modules\Checkout\Http\Requests\Store\PlaceCheckoutRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** /api/v1/checkout* (API.md §3.E). */
final class CheckoutController
{
    public function preview(CheckoutPreviewRequest $request, PreviewCheckout $action): JsonResponse
    {
        $data = new CheckoutData(
            customerId: (int) Auth::guard('customer')->id(),
            idempotencyKey: null,
            addressUuid: (string) $request->validated('address_uuid'),
            shippingQuoteId: $request->validated('shipping_quote_id'),
            shippingOptionId: $request->validated('shipping_option_id'),
            paymentMethod: PaymentMethod::from((string) $request->validated('payment_method')),
            expectedTotalCents: null,
            notes: null,
        );

        return $this->noStore(new JsonResponse(['data' => $action->execute($data)]));
    }

    public function store(PlaceCheckoutRequest $request, PlaceCheckout $action): JsonResponse
    {
        $data = new CheckoutData(
            customerId: (int) Auth::guard('customer')->id(),
            idempotencyKey: $request->idempotencyKey(),
            addressUuid: (string) $request->validated('address_uuid'),
            shippingQuoteId: (string) $request->validated('shipping_quote_id'),
            shippingOptionId: (string) $request->validated('shipping_option_id'),
            paymentMethod: PaymentMethod::from((string) $request->validated('payment_method')),
            expectedTotalCents: (int) $request->validated('expected_total_cents'),
            notes: $request->notes(),
            ip: $request->ip(),
        );
        $outcome = $action->execute($data);

        return $this->noStore(new JsonResponse(['data' => $outcome['result']], $outcome['replayed'] ? 200 : 201));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
