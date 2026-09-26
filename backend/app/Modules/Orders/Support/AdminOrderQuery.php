<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Filters/sort of the panel order list (API.md §3.G.9). Dates are America/Sao_Paulo days. */
final class AdminOrderQuery
{
    private const string TZ = 'America/Sao_Paulo';

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<Order>
     */
    public static function filtered(array $f): Builder
    {
        $q = Order::query();

        if (! empty($f['q'])) {
            $term = trim((string) $f['q']);
            $like = '%'.addcslashes($term, '%_\\').'%';
            $digits = preg_replace('/[^0-9A-Za-z]/', '', $term) ?? '';
            $q->where(static function (Builder $w) use ($term, $like, $digits): void {
                $w->where('number', 'ilike', $like)
                    ->orWhere('customer_name', 'ilike', $like)
                    ->orWhere('customer_email', 'ilike', $like)
                    ->orWhere('customer_company_name', 'ilike', $like);
                if (strlen($digits) >= 3) {
                    $w->orWhere('customer_document', 'like', '%'.strtoupper($digits).'%');
                }
                if (ctype_digit($term)) {
                    $w->orWhere('number', 'ilike', '%'.$term);
                }
            });
        }
        foreach (['status', 'payment_status', 'shipping_method_type'] as $list) {
            if (! empty($f[$list])) {
                $q->whereIn($list, (array) $f[$list]);
            }
        }
        foreach (['payment_method', 'shipping_method_id', 'customer_id'] as $eq) {
            if (isset($f[$eq]) && $f[$eq] !== null && $f[$eq] !== '') {
                $q->where($eq, $f[$eq]);
            }
        }
        self::dateRange($q, 'placed_at', $f['date_from'] ?? null, $f['date_to'] ?? null);
        self::dateRange($q, 'paid_at', $f['paid_from'] ?? null, $f['paid_to'] ?? null);
        if (isset($f['cancellation_requested']) && in_array((string) $f['cancellation_requested'], ['1', 'true'], true)) {
            $q->whereNotNull('cancellation_requested_at');
        }

        return $q;
    }

    /** @param Builder<Order> $q */
    public static function sort(Builder $q, string $sort): void
    {
        $desc = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');
        $q->orderBy($column, $desc ? 'desc' : 'asc')->orderBy('id', $desc ? 'desc' : 'asc');
    }

    /** @param Builder<Order> $q */
    private static function dateRange(Builder $q, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $q->where($column, '>=', CarbonImmutable::parse($from, self::TZ)->startOfDay()->utc());
        }
        if ($to !== null) {
            $q->where($column, '<', CarbonImmutable::parse($to, self::TZ)->addDay()->startOfDay()->utc());
        }
    }
}
