<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Settings\Support\SettingRules;
use PHPUnit\Framework\TestCase;

final class SettingRulesTest extends TestCase
{
    public function test_every_key_has_rules(): void
    {
        foreach (SettingKey::cases() as $key) {
            $rules = SettingRules::for($key);
            self::assertArrayHasKey('values.'.SettingRules::escape($key->value), $rules, $key->value);
        }
    }

    public function test_normalization(): void
    {
        self::assertSame('11222333000181', SettingRules::normalize(SettingKey::StoreDocument, '11.222.333/0001-81', null));
        self::assertSame('89010001', SettingRules::normalize(SettingKey::ShippingOriginPostalCode, '89010-001', null));
        self::assertSame(15, SettingRules::normalize(SettingKey::CartGuestTtlDays, '15', null));
        self::assertTrue(SettingRules::normalize(SettingKey::InventoryShowLowStockQuantity, 'true', null));
        self::assertSame(
            ['pix' => 45, 'boleto' => 4320],
            SettingRules::normalize(SettingKey::CheckoutPaymentExpiryMinutes, ['pix' => '45'], ['pix' => 30, 'boleto' => 4320]),
        );
        self::assertSame(['a@b.com'], SettingRules::normalize(SettingKey::NotificationsAdminAlertEmails, [' A@B.com '], []));
        self::assertSame(1.5, SettingRules::normalize(SettingKey::InventoryDefaultLowStockThreshold, '1.5', null));
    }
}
