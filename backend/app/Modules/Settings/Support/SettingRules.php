<?php

declare(strict_types=1);

namespace App\Modules\Settings\Support;

use App\Modules\Settings\Enums\SettingKey;
use App\Shared\Domain\Documents\Cnpj as CnpjValue;
use App\Shared\Domain\PostalCode as PostalCodeValue;
use App\Shared\Support\PlainText;
use App\Shared\Validation\Rules\Cnpj;
use App\Shared\Validation\Rules\PostalCode;
use Closure;

/** Typed whitelist of API.md §3.G.13: validation rules and normalization per key. */
final class SettingRules
{
    private const array UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    /**
     * Rules for "values.<key>" (+ nested fields), keyed by full dotted attribute.
     *
     * @return array<string, list<mixed>>
     */
    public static function for(SettingKey $key): array
    {
        $p = 'values.'.self::escape($key->value);
        $https = ['nullable', 'string', 'max:255', 'url:https'];

        return match ($key) {
            SettingKey::StoreName => [$p => ['required', 'string', 'min:2', 'max:120']],
            SettingKey::StoreLegalName => [$p => ['required', 'string', 'max:200']],
            SettingKey::StoreDocument => [$p => ['required', 'string', new Cnpj]],
            SettingKey::StoreAddress => [
                $p => ['required', 'array:street,number,complement,district,city,state,postal_code,city_ibge_code'],
                "{$p}.street" => ['required', 'string', 'max:200'],
                "{$p}.number" => ['required', 'string', 'max:20'],
                "{$p}.complement" => ['nullable', 'string', 'max:100'],
                "{$p}.district" => ['required', 'string', 'max:100'],
                "{$p}.city" => ['required', 'string', 'max:100'],
                "{$p}.state" => ['required', 'string', 'in:'.implode(',', self::UFS)],
                "{$p}.postal_code" => ['required', 'string', new PostalCode],
                "{$p}.city_ibge_code" => ['required', 'string', 'regex:/^\d{7}$/'],
            ],
            SettingKey::StorePhone => [$p => ['required', 'string', 'regex:/^\d{10,11}$/']],
            SettingKey::StoreWhatsapp => [$p => ['present', 'nullable', 'string', 'regex:/^\d{10,13}$/']],
            SettingKey::StoreEmail => [$p => ['required', 'string', 'email:rfc,strict', 'max:255']],
            SettingKey::StoreOpeningHours => [$p => ['required', 'string', 'max:200']],
            SettingKey::StoreSocialLinks => [
                $p => ['required', 'array:instagram,facebook,youtube'],
                "{$p}.instagram" => $https, "{$p}.facebook" => $https, "{$p}.youtube" => $https,
            ],
            SettingKey::OrdersNumberPrefix => [$p => ['required', 'string', 'regex:/^[A-Z]{1,5}-$/']],
            SettingKey::CheckoutPaymentExpiryMinutes => [
                $p => ['required', 'array:pix,boleto,credit_card,invoice'],
                "{$p}.pix" => ['sometimes', 'integer', 'min:5', 'max:1440'],
                "{$p}.boleto" => ['sometimes', 'integer', 'min:5', 'max:43200'],
                "{$p}.credit_card" => ['sometimes', 'integer', 'min:5', 'max:43200'],
                "{$p}.invoice" => ['sometimes', 'integer', 'min:5', 'max:43200'],
            ],
            SettingKey::CheckoutMinOrderCents => [$p => ['required', 'integer', 'min:0', 'max:100000000']],
            SettingKey::CartGuestTtlDays => [$p => ['required', 'integer', 'min:1', 'max:90']],
            SettingKey::ShippingQuoteTtlMinutes => [$p => ['required', 'integer', 'min:5', 'max:120']],
            SettingKey::ShippingOriginPostalCode => [$p => ['required', 'string', new PostalCode]],
            SettingKey::InventoryDefaultLowStockThreshold => [$p => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,3']],
            SettingKey::InventoryShowLowStockQuantity, SettingKey::NotificationsWhatsappEnabled => [$p => ['required', 'boolean']],
            SettingKey::LegalTermsVersion => [$p => ['required', 'string', 'max:20']],
            SettingKey::StorefrontFreeShippingBanner => [
                $p => ['required', 'array:enabled,threshold_cents,text'],
                "{$p}.enabled" => ['required', 'boolean'],
                "{$p}.threshold_cents" => ['required', 'integer', 'min:0'],
                "{$p}.text" => ['required', 'string', 'max:160'],
            ],
            SettingKey::NotificationsAdminAlertEmails => [
                $p => ['present', 'array', 'max:10'],
                "{$p}.*" => ['required', 'string', 'email:rfc,strict', 'max:255', 'distinct'],
            ],
            SettingKey::ContentAbout, SettingKey::ContentTerms, SettingKey::ContentPrivacy, SettingKey::ContentReturns => [
                $p => ['required', 'string', 'max:50000', self::plainText()],
            ],
        };
    }

    /** Normalized value to persist (types coerced, masks removed, free text cleaned). */
    public static function normalize(SettingKey $key, mixed $value, mixed $current): mixed
    {
        return match ($key) {
            SettingKey::StoreDocument => CnpjValue::normalize((string) $value),
            SettingKey::StoreAddress => [
                'street' => (string) $value['street'], 'number' => (string) $value['number'],
                'complement' => ($value['complement'] ?? null) === '' ? null : ($value['complement'] ?? null),
                'district' => (string) $value['district'], 'city' => (string) $value['city'], 'state' => (string) $value['state'],
                'postal_code' => PostalCodeValue::normalize((string) $value['postal_code']), 'city_ibge_code' => (string) $value['city_ibge_code'],
            ],
            SettingKey::StoreSocialLinks => [
                'instagram' => $value['instagram'] ?? null, 'facebook' => $value['facebook'] ?? null, 'youtube' => $value['youtube'] ?? null,
            ],
            SettingKey::ShippingOriginPostalCode => PostalCodeValue::normalize((string) $value),
            SettingKey::CheckoutPaymentExpiryMinutes => array_map('intval', [...(is_array($current) ? $current : []), ...$value]),
            SettingKey::CheckoutMinOrderCents, SettingKey::CartGuestTtlDays, SettingKey::ShippingQuoteTtlMinutes => (int) $value,
            SettingKey::InventoryDefaultLowStockThreshold => is_string($value) ? $value + 0 : $value,
            SettingKey::InventoryShowLowStockQuantity, SettingKey::NotificationsWhatsappEnabled => filter_var($value, FILTER_VALIDATE_BOOL),
            SettingKey::StorefrontFreeShippingBanner => [
                'enabled' => filter_var($value['enabled'], FILTER_VALIDATE_BOOL),
                'threshold_cents' => (int) $value['threshold_cents'],
                'text' => (string) PlainText::clean((string) $value['text']),
            ],
            SettingKey::NotificationsAdminAlertEmails => array_values(array_map(static fn ($e): string => mb_strtolower(trim((string) $e)), $value)),
            SettingKey::ContentAbout, SettingKey::ContentTerms, SettingKey::ContentPrivacy, SettingKey::ContentReturns,
            SettingKey::StoreName, SettingKey::StoreLegalName, SettingKey::StoreOpeningHours, SettingKey::LegalTermsVersion => PlainText::clean((string) $value),
            default => $value,
        };
    }

    /** Validator attribute for a key: dots escaped so "store.name" stays one key under "values". */
    public static function escape(string $key): string
    {
        return str_replace('.', '\.', $key);
    }

    private static function plainText(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== strip_tags($value)) {
                $fail('Use apenas texto, sem HTML.');
            }
        };
    }
}
