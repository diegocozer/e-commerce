<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Human labels for actors and auditable records (API.md §2.16 AuditLog).
 * Audit depends on no module, so labels are read with plain SELECTs by morph alias.
 */
final class AuditLabels
{
    /** morph alias => [table, label column] */
    private const array AUDITABLE = [
        'admin_user' => ['admin_users', 'name'],
        'customer' => ['customers', 'name'],
        'order' => ['orders', 'number'],
        'payment' => ['payments', 'uuid'],
        'product' => ['products', 'name'],
        'product_variant' => ['product_variants', 'sku'],
        'category' => ['categories', 'name'],
        'brand' => ['brands', 'name'],
        'coupon' => ['coupons', 'code'],
        'promotion' => ['promotions', 'name'],
        'shipping_method' => ['shipping_methods', 'name'],
        'setting' => ['settings', 'key'],
        'price_list' => ['price_lists', 'name'],
        'company' => ['companies', 'legal_name'],
        'role' => ['roles', 'name'],
    ];

    /**
     * @param  Collection<int, object{actor_type:mixed, actor_id:?int, auditable_type:?string, auditable_id:?int}>  $logs
     * @return array{actors: array<string, string>, auditables: array<string, string>}
     */
    public static function resolve(Collection $logs): array
    {
        $actors = [];
        foreach (['admin' => 'admin_users', 'customer' => 'customers'] as $type => $table) {
            $ids = $logs->filter(fn ($l) => self::typeValue($l->actor_type) === $type && $l->actor_id !== null)->pluck('actor_id')->unique()->values()->all();
            if ($ids !== []) {
                foreach (DB::table($table)->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
                    $actors["{$type}:{$id}"] = (string) $name;
                }
            }
        }

        $auditables = [];
        foreach ($logs->whereNotNull('auditable_type')->groupBy('auditable_type') as $type => $group) {
            if (! isset(self::AUDITABLE[$type])) {
                continue;
            }
            [$table, $column] = self::AUDITABLE[$type];
            $ids = $group->pluck('auditable_id')->unique()->values()->all();
            try {
                foreach (DB::table($table)->whereIn('id', $ids)->pluck($column, 'id') as $id => $label) {
                    $auditables["{$type}:{$id}"] = (string) $label;
                }
            } catch (\Throwable) {
                // Missing table/column in a partial install: label stays null.
            }
        }

        return ['actors' => $actors, 'auditables' => $auditables];
    }

    public static function typeValue(mixed $type): string
    {
        return $type instanceof \BackedEnum ? (string) $type->value : (string) $type;
    }
}
