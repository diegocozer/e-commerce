<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Store;

use App\Modules\Settings\Contracts\PickupPointProvider;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Illuminate\Http\JsonResponse;

/** GET /settings/public (API.md §2.11 PublicSettings). */
final class PublicSettingsController
{
    public function show(SettingsRepository $s, PickupPointProvider $pickups): JsonResponse
    {
        $address = $s->array(SettingKey::StoreAddress);
        $social = $s->array(SettingKey::StoreSocialLinks);
        $banner = $s->array(SettingKey::StorefrontFreeShippingBanner);
        $expiry = $s->array(SettingKey::CheckoutPaymentExpiryMinutes);

        return new JsonResponse(['data' => [
            'store' => [
                'name' => $s->string(SettingKey::StoreName),
                'phone' => $s->string(SettingKey::StorePhone),
                'whatsapp' => $s->string(SettingKey::StoreWhatsapp),
                'email' => $s->string(SettingKey::StoreEmail),
                'address' => $address === [] ? null : [
                    'street' => (string) ($address['street'] ?? ''), 'number' => (string) ($address['number'] ?? ''),
                    'complement' => $address['complement'] ?? null, 'district' => (string) ($address['district'] ?? ''),
                    'city' => (string) ($address['city'] ?? ''), 'state' => (string) ($address['state'] ?? ''),
                    'postal_code' => (string) ($address['postal_code'] ?? ''),
                ],
                'opening_hours' => $s->string(SettingKey::StoreOpeningHours),
                'social_links' => [
                    'instagram' => $social['instagram'] ?? null,
                    'facebook' => $social['facebook'] ?? null,
                    'youtube' => $social['youtube'] ?? null,
                ],
            ],
            'pickup_points' => $pickups->activePickupPoints(),
            'free_shipping_banner' => $banner === [] ? null : [
                'enabled' => (bool) ($banner['enabled'] ?? false),
                'threshold_cents' => (int) ($banner['threshold_cents'] ?? 0),
                'text' => (string) ($banner['text'] ?? ''),
            ],
            'terms_version' => (string) $s->string(SettingKey::LegalTermsVersion),
            'checkout' => [
                'payment_methods' => ['pix'],
                'pix_expiry_minutes' => (int) ($expiry['pix'] ?? 30),
                'min_order_cents' => $s->int(SettingKey::CheckoutMinOrderCents),
            ],
            'features' => ['show_low_stock_quantity' => $s->bool(SettingKey::InventoryShowLowStockQuantity)],
        ]]);
    }
}
