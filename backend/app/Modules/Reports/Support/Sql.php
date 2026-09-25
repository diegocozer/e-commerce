<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Reports\DTOs\ReportPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** SQL fragments shared by the report queries (PostgreSQL). */
final class Sql
{
    /**
     * RN-REL-001: a counted sale = payment approved and not later refunded or cancelled.
     * Matches the partial index orders_paid_report_index (paid_at) WHERE payment_status = 'approved'.
     */
    public static function validSale(string $alias = 'o'): string
    {
        return "{$alias}.payment_status = 'approved' AND {$alias}.status <> 'cancelled'";
    }

    /** Local (America/Sao_Paulo) date of a timestamptz column. */
    public static function localDate(string $column): string
    {
        return "({$column} AT TIME ZONE '".ReportPeriod::TIMEZONE."')::date";
    }

    /** Start date (local) of the day/week (ISO, Monday)/month bucket of a timestamptz column. */
    public static function bucket(string $column, string $groupBy): string
    {
        if (! in_array($groupBy, ['day', 'week', 'month'], true)) {
            throw new InvalidArgumentException("Invalid group_by [{$groupBy}].");
        }

        return "date_trunc('{$groupBy}', {$column} AT TIME ZONE '".ReportPeriod::TIMEZONE."')::date";
    }

    /** Restricts a timestamptz column to the period (half-open UTC bounds). */
    public static function inPeriod(Builder $query, string $column, ReportPeriod $period): Builder
    {
        return $query->where($column, '>=', $period->startUtc())->where($column, '<', $period->endUtcExclusive());
    }

    /**
     * Filters product ids (column) by primary category (with descendants) and brand.
     */
    public static function applyCatalogFilters(Builder $query, string $productAlias, ?int $categoryId, ?int $brandId): Builder
    {
        if ($categoryId !== null) {
            $query->whereRaw(
                "{$productAlias}.primary_category_id IN (
                    WITH RECURSIVE tree AS (
                        SELECT id FROM categories WHERE id = ?
                        UNION ALL
                        SELECT c.id FROM categories c JOIN tree t ON c.parent_id = t.id
                    ) SELECT id FROM tree)",
                [$categoryId],
            );
        }
        if ($brandId !== null) {
            $query->where("{$productAlias}.brand_id", $brandId);
        }

        return $query;
    }

    /**
     * Effective low stock threshold (DATABASE.md §3.3.1): override ?? setting ?? 10.
     * The setting is read with a plain SELECT (Reports is read-only and does not import Settings).
     */
    public static function effectiveThreshold(string $inventoryAlias = 'i'): string
    {
        return "COALESCE({$inventoryAlias}.low_stock_threshold, (SELECT (s.value #>> '{}')::numeric FROM settings s "
            ."WHERE s.key = 'inventory.default_low_stock_threshold' AND jsonb_typeof(s.value) IN ('number', 'string')), 10)";
    }

    /** numeric string → JSON number with up to 3 decimals. */
    public static function decimal3(mixed $value): int|float
    {
        if ($value === null) {
            return 0;
        }
        $float = round((float) $value, 3);

        return floor($float) === $float ? (int) $float : $float;
    }

    public static function int(mixed $value): int
    {
        return (int) ($value ?? 0);
    }

    public static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** Ratio in basis points (1250 = 12,5%), null when the denominator is zero. */
    public static function bp(int|float $numerator, int|float $denominator): ?int
    {
        return $denominator == 0 ? null : (int) round($numerator * 10000 / $denominator);
    }

    /** Integer division rounded half up; null when dividing by zero. */
    public static function avg(int $total, int $count): ?int
    {
        return $count === 0 ? null : (int) round($total / $count);
    }

    /** ISO-8601 UTC string of a timestamptz value returned by PostgreSQL. */
    public static function isoDateTime(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Zero-filled bucket start dates between from and to.
     *
     * @return list<string>
     */
    public static function buckets(ReportPeriod $period, string $groupBy): array
    {
        $cursor = match ($groupBy) {
            'week' => $period->from->startOfWeek(CarbonInterface::MONDAY),
            'month' => $period->from->startOfMonth(),
            default => $period->from,
        };
        $dates = [];
        while ($cursor->lessThanOrEqualTo($period->to)) {
            $dates[] = $cursor->toDateString();
            $cursor = match ($groupBy) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $dates;
    }
}
