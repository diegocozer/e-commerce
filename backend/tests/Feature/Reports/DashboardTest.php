<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;

final class DashboardTest extends ReportsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(self::sp('2026-09-15 15:00'));
    }

    public function test_requires_dashboard_view(): void
    {
        $this->actingAsAdminWith('reports.view');
        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_blocks_are_null_without_permission(): void
    {
        $this->actingAsAdminWith('dashboard.view');

        $this->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.sales', null)
            ->assertJsonPath('data.queues', null)
            ->assertJsonPath('data.todays_deliveries', null)
            ->assertJsonPath('data.low_stock', null)
            ->assertJsonStructure(['data' => ['generated_at']]);
    }

    public function test_kpis_series_queues_and_low_stock(): void
    {
        $this->actingAsAdminWith('dashboard.view', 'reports.sales', 'orders.view', 'inventory.view');
        $variant = ProductVariant::factory()->withStock('3')->create();

        $this->item($this->paidOrder('2026-09-15 09:00', 8000), $variant, '2', 8000);  // today 10 000
        $this->paidOrder('2026-09-15 00:10', 3000, ['shipping_cents' => 0]);          // today 3 000
        $this->paidOrder('2026-09-14 23:50', 5000, ['shipping_cents' => 0]);          // yesterday 5 000
        $this->refund($this->paidOrder('2026-09-15 10:00', 99000), '2026-09-15 11:00'); // excluded
        $this->paidOrder('2026-08-10 10:00', 4000, ['shipping_cents' => 0]);          // prev month ≤ day 15
        $this->paidOrder('2026-08-20 10:00', 7000, ['shipping_cents' => 0]);          // prev month after day 15
        Order::factory()->create();                                                   // pending_payment
        Order::factory()->paid()->create(['estimated_delivery_date' => '2026-09-15', 'paid_at' => self::sp('2026-07-01 10:00')]); // to pick + delivery today

        $response = $this->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.sales.today.revenue_cents', ['value' => 13000, 'previous' => 5000, 'change_bp' => 16000])
            ->assertJsonPath('data.sales.today.orders_paid.value', 2)
            ->assertJsonPath('data.sales.month.revenue_cents.value', 18000)
            ->assertJsonPath('data.sales.month.revenue_cents.previous', 4000)
            ->assertJsonPath('data.sales.avg_ticket_cents_month.value', 6000)
            ->assertJsonCount(30, 'data.sales.revenue_series_30d')
            ->assertJsonPath('data.sales.revenue_series_30d.29', ['date' => '2026-09-15', 'revenue_cents' => 13000, 'orders_paid' => 2])
            ->assertJsonPath('data.sales.revenue_series_30d.28.revenue_cents', 5000)
            ->assertJsonPath('data.sales.revenue_series_30d.0.date', '2026-08-17')
            ->assertJsonPath('data.sales.top_products_30d.0.variant_id', $variant->id)
            ->assertJsonPath('data.queues.pending_payment', 1)
            ->assertJsonPath('data.low_stock.total', 1)
            ->assertJsonPath('data.low_stock.items.0.variant_id', $variant->id)
            ->assertJsonPath('data.low_stock.items.0.low_stock_threshold', 10)
            ->assertJsonPath('data.low_stock.items.0.stock_unit_abbr', 'un');

        // Paid orders from factories keep status "paid": 5 valid + the delivery one.
        self::assertSame(6, $response->json('data.queues.to_pick'));
        self::assertCount(1, $response->json('data.todays_deliveries'));
    }
}
