<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\DTOs\ReportPeriod;
use App\Modules\Reports\Support\Sql;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /admin/dashboard data (API.md §2.16 Dashboard / §3.G.2). Every block is computed with a
 * handful of aggregate queries and cached for 60 s (key per local day and minute bucket);
 * the controller nulls the blocks the admin may not see.
 */
final class DashboardQuery
{
    public const int CACHE_SECONDS = 60;

    public const int LOW_STOCK_ITEMS = 10;

    public const int DELIVERIES_LIMIT = 50;

    public function __construct(private readonly Cache $cache) {}

    /** @return array{generated_at: string, sales: array<string, mixed>, queues: array<string, int>, todays_deliveries: list<array<string, mixed>>, low_stock: array{items: list<array<string, mixed>>, total: int}} */
    public function get(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(ReportPeriod::TIMEZONE);

        return $this->cache->remember(
            'reports:dashboard:'.$now->setTimezone(ReportPeriod::TIMEZONE)->toDateString(),
            self::CACHE_SECONDS,
            fn (): array => $this->compute($now),
        );
    }

    /** @return array<string, mixed> */
    public function compute(CarbonImmutable $now): array
    {
        $now = $now->setTimezone(ReportPeriod::TIMEZONE);

        return [
            'generated_at' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
            'sales' => $this->sales($now),
            'queues' => $this->queues(),
            'todays_deliveries' => $this->todaysDeliveries($now),
            'low_stock' => $this->lowStock(),
        ];
    }

    /** @return array<string, mixed> */
    private function sales(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();
        $yesterday = $today->subDay();
        $monthStart = $today->startOfMonth();
        $prevMonthStart = $monthStart->subMonthNoOverflow();
        // "mês anterior até o mesmo dia" (clamped to the previous month length).
        $prevMonthEnd = $prevMonthStart->addDays(min($today->day, $prevMonthStart->daysInMonth));
        $seriesStart = $today->subDays(29);
        $from = $prevMonthStart->min($seriesStart);

        $u = static fn (CarbonImmutable $d): string => $d->utc()->toIso8601String();
        $valid = Sql::validSale();
        $window = static fn (string $a, string $b): string => "o.paid_at >= '{$a}' AND o.paid_at < '{$b}'";
        $ranges = [
            'today' => $window($u($today), $u($tomorrow)),
            'yesterday' => $window($u($yesterday), $u($today)),
            'month' => $window($u($monthStart), $u($tomorrow)),
            'prev_month' => $window($u($prevMonthStart), $u($prevMonthEnd)),
        ];

        $select = [];
        foreach ($ranges as $key => $condition) {
            $select[] = "COUNT(*) FILTER (WHERE {$condition}) AS {$key}_orders";
            $select[] = "COALESCE(SUM(o.total_cents) FILTER (WHERE {$condition}), 0) AS {$key}_revenue";
        }
        $k = DB::table('orders as o')
            ->selectRaw(implode(', ', $select))
            ->whereRaw($valid)
            ->where('o.paid_at', '>=', $u($from))->where('o.paid_at', '<', $u($tomorrow))
            ->first();
        $v = static fn (string $f): int => Sql::int($k?->{$f});

        $series = DB::table('orders as o')
            ->selectRaw(Sql::localDate('o.paid_at').' AS day, COUNT(*) AS orders_paid, SUM(o.total_cents) AS revenue_cents')
            ->whereRaw($valid)
            ->where('o.paid_at', '>=', $u($seriesStart))->where('o.paid_at', '<', $u($tomorrow))
            ->groupByRaw('1')
            ->get()->keyBy(fn (object $r): string => (string) $r->day);
        $points = [];
        for ($day = $seriesStart; $day->lessThan($tomorrow); $day = $day->addDay()) {
            $r = $series->get($day->toDateString());
            $points[] = ['date' => $day->toDateString(), 'revenue_cents' => Sql::int($r?->revenue_cents), 'orders_paid' => Sql::int($r?->orders_paid)];
        }

        $top = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereRaw($valid)
            ->where('o.paid_at', '>=', $u($seriesStart))->where('o.paid_at', '<', $u($tomorrow))
            ->select('oi.variant_id', 'oi.sale_unit')
            ->selectRaw('(array_agg(oi.sku ORDER BY oi.id DESC))[1] AS sku')
            ->selectRaw("(array_agg(oi.product_name || ' — ' || oi.variant_name ORDER BY oi.id DESC))[1] AS name")
            ->selectRaw('SUM(oi.billable_quantity) AS quantity, SUM(oi.total_cents) AS revenue_cents')
            ->groupBy('oi.variant_id', 'oi.sale_unit')
            ->orderByDesc('revenue_cents')->orderBy('oi.variant_id')
            ->limit(5)
            ->get()
            ->map(fn (object $r): array => [
                'variant_id' => (int) $r->variant_id,
                'sku' => $r->sku,
                'name' => $r->name,
                'sale_unit' => $r->sale_unit,
                'quantity' => Sql::decimal3($r->quantity),
                'revenue_cents' => (int) $r->revenue_cents,
            ])->all();

        return [
            'today' => [
                'orders_paid' => self::kpi($v('today_orders'), $v('yesterday_orders')),
                'revenue_cents' => self::kpi($v('today_revenue'), $v('yesterday_revenue')),
            ],
            'month' => [
                'orders_paid' => self::kpi($v('month_orders'), $v('prev_month_orders')),
                'revenue_cents' => self::kpi($v('month_revenue'), $v('prev_month_revenue')),
            ],
            'avg_ticket_cents_month' => self::kpi(
                Sql::avg($v('month_revenue'), $v('month_orders')) ?? 0,
                Sql::avg($v('prev_month_revenue'), $v('prev_month_orders')) ?? 0,
            ),
            'revenue_series_30d' => $points,
            'top_products_30d' => $top,
        ];
    }

    /** @return array{value: int, previous: int, change_bp: int|null} */
    public static function kpi(int $value, int $previous): array
    {
        return ['value' => $value, 'previous' => $previous, 'change_bp' => $previous === 0 ? null : Sql::bp($value - $previous, $previous)];
    }

    /** @return array<string, int> */
    private function queues(): array
    {
        $r = DB::table('orders as o')
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'pending_payment') AS pending_payment")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'paid') AS to_pick")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'processing' AND o.shipping_method_type <> 'pickup') AS to_ship")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'processing' AND o.shipping_method_type = 'pickup') AS to_prepare_pickup")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'ready_for_pickup') AS ready_for_pickup")
            ->selectRaw("COUNT(*) FILTER (WHERE o.status = 'shipped') AS shipped")
            ->selectRaw("COUNT(*) FILTER (WHERE o.cancellation_requested_at IS NOT NULL AND o.status IN ('paid', 'processing')) AS cancellation_requests")
            ->whereIn('o.status', ['pending_payment', 'paid', 'processing', 'ready_for_pickup', 'shipped'])
            ->first();

        return array_map('intval', (array) $r);
    }

    /** @return list<array<string, mixed>> */
    private function todaysDeliveries(CarbonImmutable $now): array
    {
        return DB::table('orders as o')
            ->whereIn('o.shipping_method_type', ['own_delivery', 'pickup'])
            ->whereIn('o.status', ['paid', 'processing', 'shipped', 'ready_for_pickup'])
            ->where('o.estimated_delivery_date', '<=', $now->toDateString())
            ->orderBy('o.estimated_delivery_date')->orderBy('o.id')
            ->limit(self::DELIVERIES_LIMIT)
            ->get(['o.id', 'o.number', 'o.status', 'o.shipping_method_type', 'o.shipping_method_name', 'o.shipping_district', 'o.shipping_city', 'o.estimated_delivery_date'])
            ->map(fn (object $r): array => [
                'order_id' => (int) $r->id,
                'number' => $r->number,
                'status' => $r->status,
                'shipping_method_type' => $r->shipping_method_type,
                'shipping_method_name' => $r->shipping_method_name,
                'district' => $r->shipping_district,
                'city' => $r->shipping_city,
                'estimated_delivery_date' => $r->estimated_delivery_date === null ? null : substr((string) $r->estimated_delivery_date, 0, 10),
            ])->all();
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function lowStock(): array
    {
        $threshold = Sql::effectiveThreshold('i');
        $base = DB::table('inventory as i')
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereNull('v.deleted_at')->whereNull('p.deleted_at')->where('v.is_active', true)
            ->whereRaw("(i.on_hand - i.reserved) <= {$threshold}");

        $total = (clone $base)->count();
        $items = $base
            ->select('v.id as variant_id', 'v.sku', 'v.name as variant_name', 'p.id as product_id', 'p.name as product_name',
                'p.slug', 'p.is_active', 'p.sale_unit', 'i.on_hand', 'i.reserved', 'i.low_stock_threshold', 'i.low_stock_alerted_at', 'i.updated_at')
            ->selectRaw("{$threshold} AS threshold")
            ->orderByRaw('(i.on_hand - i.reserved) ASC')->orderBy('v.id')
            ->limit(self::LOW_STOCK_ITEMS)
            ->get()
            ->map(fn (object $r): array => [
                'variant_id' => (int) $r->variant_id,
                'sku' => $r->sku,
                'variant_name' => $r->variant_name,
                'product' => ['id' => (int) $r->product_id, 'name' => $r->product_name, 'slug' => $r->slug, 'is_active' => (bool) $r->is_active],
                'sale_unit' => $r->sale_unit,
                'stock_unit_abbr' => SaleUnit::tryFrom((string) $r->sale_unit)?->abbreviation() ?? '',
                'on_hand' => Sql::decimal3($r->on_hand),
                'reserved' => Sql::decimal3($r->reserved),
                'available' => Sql::decimal3((float) $r->on_hand - (float) $r->reserved),
                'low_stock_threshold' => Sql::decimal3($r->threshold),
                'low_stock_threshold_override' => $r->low_stock_threshold === null ? null : Sql::decimal3($r->low_stock_threshold),
                'is_low_stock' => true,
                'low_stock_alerted_at' => Sql::isoDateTime($r->low_stock_alerted_at),
                'updated_at' => Sql::isoDateTime($r->updated_at),
            ])->all();

        return ['items' => $items, 'total' => $total];
    }
}
