<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

/**
 * Whitelisted settings (API.md §3.G.13, canonical per ADR-028; seeds of
 * DATABASE.md §7.1) with type, default value, group and visibility.
 * The default is returned when the row does not exist.
 */
enum SettingKey: string
{
    case StoreName = 'store.name';
    case StoreLegalName = 'store.legal_name';
    case StoreDocument = 'store.document';
    case StoreAddress = 'store.address';
    case StorePhone = 'store.phone';
    case StoreWhatsapp = 'store.whatsapp';
    case StoreEmail = 'store.email';
    case StoreOpeningHours = 'store.opening_hours';
    case StoreSocialLinks = 'store.social_links';
    case OrdersNumberPrefix = 'orders.number_prefix';
    /** ADR-031: canonical key; per-method expiry in minutes (API/UX "pix_expiry_minutes" = its `pix` entry). */
    case CheckoutPaymentExpiryMinutes = 'checkout.payment_expiry_minutes';
    case CheckoutMinOrderCents = 'checkout.min_order_cents';
    case CartGuestTtlDays = 'cart.guest_ttl_days';
    case ShippingQuoteTtlMinutes = 'shipping.quote_ttl_minutes';
    case ShippingOriginPostalCode = 'shipping.origin_postal_code';
    case InventoryDefaultLowStockThreshold = 'inventory.default_low_stock_threshold';
    case InventoryShowLowStockQuantity = 'inventory.show_low_stock_quantity';
    case LegalTermsVersion = 'legal.terms_version';
    case StorefrontFreeShippingBanner = 'storefront.free_shipping_banner';
    case NotificationsWhatsappEnabled = 'notifications.whatsapp_enabled';
    case NotificationsAdminAlertEmails = 'notifications.admin_alert_emails';
    case ContentAbout = 'content.about';
    case ContentTerms = 'content.terms';
    case ContentPrivacy = 'content.privacy';
    case ContentReturns = 'content.returns';

    /** 'string' | 'integer' | 'decimal' | 'boolean' | 'object' | 'string_list' | 'text' */
    public function type(): string
    {
        return match ($this) {
            self::StoreAddress, self::StoreSocialLinks, self::StorefrontFreeShippingBanner,
            self::CheckoutPaymentExpiryMinutes => 'object',
            self::CheckoutMinOrderCents, self::CartGuestTtlDays,
            self::ShippingQuoteTtlMinutes => 'integer',
            self::InventoryDefaultLowStockThreshold => 'decimal',
            self::InventoryShowLowStockQuantity, self::NotificationsWhatsappEnabled => 'boolean',
            self::NotificationsAdminAlertEmails => 'string_list',
            self::ContentAbout, self::ContentTerms, self::ContentPrivacy, self::ContentReturns => 'text',
            default => 'string',
        };
    }

    public function defaultValue(): mixed
    {
        return match ($this) {
            self::StoreName => 'CV Suprimentos',
            self::StoreLegalName => 'CV Suprimentos para Comunicação Visual Ltda',
            self::StoreDocument => '11222333000181',
            self::StoreAddress => [
                'street' => 'Rua XV de Novembro', 'number' => '1000', 'complement' => null, 'district' => 'Centro',
                'city' => 'Blumenau', 'state' => 'SC', 'postal_code' => '89010001', 'city_ibge_code' => '4202404',
            ],
            self::StorePhone => '4733330000',
            self::StoreWhatsapp => null,
            self::StoreEmail => 'contato@example.com',
            self::StoreOpeningHours => 'Seg–Sex 8h–18h',
            self::StoreSocialLinks => ['instagram' => null, 'facebook' => null, 'youtube' => null],
            self::OrdersNumberPrefix => 'CV-',
            self::CheckoutPaymentExpiryMinutes => ['pix' => 30, 'boleto' => 4320, 'credit_card' => 30, 'invoice' => 10080],
            self::CheckoutMinOrderCents => 0,
            self::CartGuestTtlDays => 30,
            self::ShippingQuoteTtlMinutes => 30,
            self::ShippingOriginPostalCode => '89010001',
            self::InventoryDefaultLowStockThreshold => 10,
            self::InventoryShowLowStockQuantity => false,
            self::LegalTermsVersion => '2026-01',
            self::StorefrontFreeShippingBanner => [
                'enabled' => true, 'threshold_cents' => 50000, 'text' => 'Frete grátis na região de Blumenau acima de R$ 500',
            ],
            self::NotificationsWhatsappEnabled => false,
            self::NotificationsAdminAlertEmails => [],
            self::ContentAbout => 'A CV Suprimentos atende gráficas e comunicadores visuais com lonas, vinis, tintas e acessórios.',
            self::ContentTerms => 'Termos de uso — versão 2026-01.',
            self::ContentPrivacy => 'Política de privacidade — versão 2026-01.',
            self::ContentReturns => 'Política de trocas e devoluções.',
        };
    }

    /** 'store' | 'checkout' | 'shipping' | 'inventory' | 'legal' | 'storefront' | 'notifications' | 'content' */
    public function group(): string
    {
        return match (explode('.', $this->value)[0]) {
            'orders', 'checkout', 'cart' => 'checkout',
            default => explode('.', $this->value)[0],
        };
    }

    /** Exposed by the public storefront settings endpoint. */
    public function isPublic(): bool
    {
        return match ($this) {
            self::StoreLegalName, self::StoreDocument, self::OrdersNumberPrefix, self::CartGuestTtlDays,
            self::ShippingQuoteTtlMinutes, self::ShippingOriginPostalCode, self::InventoryDefaultLowStockThreshold,
            self::NotificationsWhatsappEnabled, self::NotificationsAdminAlertEmails => false,
            default => true,
        };
    }
}
