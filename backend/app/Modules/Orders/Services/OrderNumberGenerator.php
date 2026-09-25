<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Illuminate\Support\Facades\DB;

/**
 * Order numbers (DB-11): nextval('order_number_seq') formatted by the
 * application as "{prefix}{n padded to 6}" — "CV-000123"; beyond 999999 it
 * grows ("CV-1000000") instead of truncating. Call inside the checkout
 * transaction; gaps after rollbacks are acceptable.
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function next(): string
    {
        $sequence = (int) DB::selectOne("SELECT nextval('order_number_seq') AS n")->n;

        return self::format($this->settings->string(SettingKey::OrdersNumberPrefix) ?? 'CV-', $sequence);
    }

    public static function format(string $prefix, int $sequence): string
    {
        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
