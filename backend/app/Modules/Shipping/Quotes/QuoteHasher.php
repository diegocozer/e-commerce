<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Quotes;

use App\Modules\Shipping\DTOs\ShippingRequest;

/** sha256 of the canonical JSON of items + CEP + subtotal + coupon flag (SHIPPING.md §4.9). */
final class QuoteHasher
{
    /** @param  list<array<string, mixed>>  $cartItemConfigs */
    public function hash(ShippingRequest $request, array $cartItemConfigs): string
    {
        $items = array_map(static fn (array $i): array => [
            'height_mm' => isset($i['height_mm']) ? (int) $i['height_mm'] : null,
            'pieces' => isset($i['pieces']) ? (int) $i['pieces'] : null,
            'quantity_milli' => isset($i['quantity_milli']) ? (int) $i['quantity_milli'] : null,
            'variant_id' => (int) $i['variant_id'],
            'width_mm' => isset($i['width_mm']) ? (int) $i['width_mm'] : null,
        ], $cartItemConfigs);
        usort($items, static fn (array $a, array $b): int => [$a['variant_id'], $a['width_mm'], $a['height_mm'], $a['quantity_milli'], $a['pieces']]
            <=> [$b['variant_id'], $b['width_mm'], $b['height_mm'], $b['quantity_milli'], $b['pieces']]);

        $payload = [
            'coupon_free_shipping' => $request->couponFreeShipping,
            'items' => $items,
            'postal_code' => $request->destination->postalCode,
            'subtotal_cents' => $request->subtotalCents,
            'v' => 1,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
