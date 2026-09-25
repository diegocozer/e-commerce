<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Orders\Models\Order;

final class SalesAndRevenueReportTest extends ReportsTestCase
{
    public function test_sales_excludes_refunded_cancelled_and_unpaid_orders(): void
    {
        $this->actingAsAdminWith('reports.view');

        $this->paidOrder('2026-09-10 10:00', 10000);                       // 12 000
        $this->paidOrder('2026-09-10 11:00', 5000, ['discount_cents' => 1000, 'shipping_discount_cents' => 2000]); // 4 000
        $this->refund($this->paidOrder('2026-09-10 12:00', 30000), '2026-09-11 09:00');
        // Paid then cancelled without (yet) refund: not revenue.
        $cancelled = $this->paidOrder('2026-09-10 13:00', 7000);
        Order::query()->whereKey($cancelled->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason_code' => 'admin']);
        Order::factory()->create(['placed_at' => self::sp('2026-09-10 09:00')]); // pending_payment

        $response = $this->report('sales', ['date_from' => '2026-09-10', 'date_to' => '2026-09-11'])->assertOk();

        $response->assertJsonPath('data.report', 'sales')
            ->assertJsonPath('data.period', ['date_from' => '2026-09-10', 'date_to' => '2026-09-11', 'group_by' => 'day', 'timezone' => 'America/Sao_Paulo'])
            ->assertJsonPath('data.summary', ['orders_paid' => 4, 'revenue_cents' => 16000, 'avg_ticket_cents' => 8000, 'orders_refunded' => 1])
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0', [
                'period_start' => '2026-09-10', 'orders_paid' => 4, 'orders_refunded' => 1, 'revenue_cents' => 16000,
                'products_revenue_cents' => 14000, 'shipping_cents' => 2000, 'discount_cents' => 1000, 'avg_ticket_cents' => 8000,
            ])
            ->assertJsonPath('data.rows.1.orders_paid', 0)
            ->assertJsonPath('data.rows.1.avg_ticket_cents', null)
            ->assertJsonPath('data.totals.revenue_cents', 16000);
    }

    public function test_days_are_split_in_sao_paulo_timezone(): void
    {
        $this->actingAsAdminWith('reports.sales');

        // 23:30 local on the 9th = 02:30 UTC on the 10th.
        $this->paidOrder('2026-09-09 23:30', 1000, ['shipping_cents' => 0]);
        // 00:00 local on the 10th.
        $this->paidOrder('2026-09-10 00:00', 2000, ['shipping_cents' => 0]);
        // 00:00 local on the 11th: outside the range.
        $this->paidOrder('2026-09-11 00:00', 4000, ['shipping_cents' => 0]);

        $this->report('sales', ['date_from' => '2026-09-09', 'date_to' => '2026-09-10'])->assertOk()
            ->assertJsonPath('data.rows.0.revenue_cents', 1000)
            ->assertJsonPath('data.rows.1.revenue_cents', 2000)
            ->assertJsonPath('data.summary.revenue_cents', 3000);
    }

    public function test_group_by_month_zero_fills_buckets(): void
    {
        $this->actingAsAdminWith('reports.view');
        $this->paidOrder('2026-08-15 10:00', 1000, ['shipping_cents' => 0]);

        $this->report('sales', ['date_from' => '2026-07-20', 'date_to' => '2026-09-05', 'group_by' => 'month'])->assertOk()
            ->assertJsonPath('data.period.group_by', 'month')
            ->assertJsonPath('data.rows.*.period_start', ['2026-07-01', '2026-08-01', '2026-09-01'])
            ->assertJsonPath('data.rows.1.revenue_cents', 1000)
            ->assertJsonPath('data.rows.0.revenue_cents', 0);
    }

    public function test_revenue_subtracts_refunds_in_the_refund_period(): void
    {
        $this->actingAsAdminWith('reports.view');
        $this->paidOrder('2026-09-01 10:00', 10000);                      // 12 000
        $this->refund($this->paidOrder('2026-08-20 10:00', 5000), '2026-09-02 10:00'); // 7 000 refunded in Sept

        $this->report('revenue', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'group_by' => 'month'])->assertOk()
            ->assertJsonPath('data.summary', ['gross_cents' => 12000, 'discount_cents' => 0, 'shipping_cents' => 2000, 'refunds_cents' => 7000, 'net_cents' => 5000])
            ->assertJsonCount(1, 'data.rows');
    }
}
