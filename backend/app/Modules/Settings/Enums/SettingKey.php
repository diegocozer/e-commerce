<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

/**
 * Known settings (DATABASE.md §7.1) with their default value, group and
 * visibility. Defaults are used when the row does not exist.
 */
enum SettingKey: string
{
    case StoreName = 'store.name';
    case StoreDocument = 'store.document';
    case StoreAddress = 'store.address';
    case StorePhone = 'store.phone';
    case StoreEmail = 'store.email';
    case StorePostalCode = 'store.postal_code';
    case OrdersNumberPrefix = 'orders.number_prefix';
    case CheckoutPaymentExpiryMinutes = 'checkout.payment_expiry_minutes';
    case CartGuestTtlDays = 'cart.guest_ttl_days';
    case ShippingQuoteTtlMinutes = 'shipping.quote_ttl_minutes';
    case InventoryDefaultLowStockThreshold = 'inventory.default_low_stock_threshold';
    case LegalTermsVersion = 'legal.terms_version';
    case StorefrontFreeShippingBanner = 'storefront.free_shipping_banner';

    public function defaultValue(): mixed
    {
        return match ($this) {
            self::StoreName => 'CV Suprimentos',
            self::StoreDocument => '11222333000181',
            self::StoreAddress => [
                'street' => 'Rua XV de Novembro', 'number' => '1000', 'district' => 'Centro', 'city' => 'Blumenau',
                'state' => 'SC', 'postal_code' => '89010001', 'city_ibge_code' => '4202404',
            ],
            self::StorePhone => '4733330000',
            self::StoreEmail => 'contato@example.com',
            self::StorePostalCode => '89010001',
            self::OrdersNumberPrefix => 'CV-',
            self::CheckoutPaymentExpiryMinutes => ['pix' => 30, 'boleto' => 4320, 'credit_card' => 30, 'invoice' => 10080],
            self::CartGuestTtlDays => 30,
            self::ShippingQuoteTtlMinutes => 30,
            self::InventoryDefaultLowStockThreshold => 10,
            self::LegalTermsVersion => '2026-01',
            self::StorefrontFreeShippingBanner => [
                'enabled' => true, 'threshold_cents' => 50000, 'text' => 'Frete grátis na região de Blumenau acima de R$ 500',
            ],
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::StoreName, self::StoreDocument, self::StoreAddress, self::StorePhone, self::StoreEmail, self::StorePostalCode => 'store',
            self::OrdersNumberPrefix, self::CheckoutPaymentExpiryMinutes, self::CartGuestTtlDays => 'checkout',
            self::ShippingQuoteTtlMinutes => 'shipping',
            self::InventoryDefaultLowStockThreshold => 'inventory',
            self::LegalTermsVersion => 'legal',
            self::StorefrontFreeShippingBanner => 'storefront',
        };
    }

    /** Exposed by the public storefront settings endpoint. */
    public function isPublic(): bool
    {
        return match ($this) {
            self::StoreName, self::StoreAddress, self::StorePhone, self::StoreEmail,
            self::LegalTermsVersion, self::StorefrontFreeShippingBanner => true,
            default => false,
        };
    }
}
