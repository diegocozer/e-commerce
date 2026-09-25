<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Reports\Contracts\Report;
use App\Modules\Reports\Queries\CouponsReport;
use App\Modules\Reports\Queries\CustomersReport;
use App\Modules\Reports\Queries\InventoryMovementsReport;
use App\Modules\Reports\Queries\InventoryReport;
use App\Modules\Reports\Queries\MarginReport;
use App\Modules\Reports\Queries\OrdersReport;
use App\Modules\Reports\Queries\ProductsReport;
use App\Modules\Reports\Queries\RevenueReport;
use App\Modules\Reports\Queries\SalesReport;
use App\Modules\Reports\Queries\ShippingReport;
use Illuminate\Contracts\Container\Container;

/** report name (route segment) => query class (API.md §3.G.15). */
final class ReportRegistry
{
    public const array REPORTS = [
        'sales' => SalesReport::class,
        'products' => ProductsReport::class,
        'revenue' => RevenueReport::class,
        'customers' => CustomersReport::class,
        'inventory' => InventoryReport::class,
        'inventory-movements' => InventoryMovementsReport::class,
        'orders' => OrdersReport::class,
        'shipping' => ShippingReport::class,
        'margin' => MarginReport::class,
        'coupons' => CouponsReport::class,
    ];

    /** Ranking reports: `limit` defaults to 100 in JSON; others default to 500. */
    public const array RANKINGS = ['products', 'customers', 'margin', 'coupons', 'shipping'];

    public function __construct(private readonly Container $container) {}

    public function find(string $name): ?Report
    {
        $class = self::REPORTS[$name] ?? null;

        return $class === null ? null : $this->container->make($class);
    }
}
