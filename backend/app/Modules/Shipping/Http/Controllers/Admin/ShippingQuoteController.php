<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Actions\ShippingAdminSupport;
use App\Modules\Shipping\Models\ShippingQuote;
use Illuminate\Http\JsonResponse;

/** GET /admin/shipping/quotes/{uuid} — persisted quote for support, including `unavailable`. */
final class ShippingQuoteController
{
    public function show(string $uuid): JsonResponse
    {
        $quote = preg_match('/^[0-9a-f-]{36}$/i', $uuid) === 1 ? ShippingQuote::query()->where('uuid', $uuid)->first() : null;
        abort_if($quote === null, 404);

        return new JsonResponse(['data' => [
            'quote_id' => $quote->uuid,
            'cart_id' => $quote->cart_id,
            'customer_id' => $quote->customer_id,
            'postal_code' => $quote->postal_code,
            'city_ibge_code' => $quote->city_ibge_code,
            'state' => $quote->state,
            'request_hash' => $quote->request_hash,
            'subtotal_cents' => $quote->subtotal_cents,
            'total_weight_grams' => $quote->total_weight_grams,
            'total_volume_cm3' => $quote->total_volume_cm3,
            'coupon_free_shipping' => $quote->coupon_free_shipping,
            'options' => $quote->options,
            'unavailable' => $quote->unavailable,
            'expires_at' => ShippingAdminSupport::iso($quote->expires_at),
            'created_at' => ShippingAdminSupport::iso($quote->created_at),
            'expired' => $quote->isExpired(),
        ]]);
    }
}
