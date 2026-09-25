<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\ProductVariant;

final class ReportAccessAndValidationTest extends ReportsTestCase
{
    private const array RANGE = ['date_from' => '2026-09-01', 'date_to' => '2026-09-30'];

    public function test_guest_is_unauthenticated(): void
    {
        $this->report('sales', self::RANGE)->assertUnauthorized();
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_admin_without_report_permissions_is_forbidden(): void
    {
        $this->actingAsAdminWith('orders.view');

        $this->report('sales', self::RANGE)->assertForbidden();
        $this->report('inventory')->assertForbidden();
    }

    public function test_granular_permissions_limit_reports(): void
    {
        $this->actingAsAdminWith('reports.sales');
        $this->report('sales', self::RANGE)->assertOk();
        $this->report('products', self::RANGE)->assertOk();
        $this->report('revenue', self::RANGE)->assertForbidden();
        $this->report('inventory')->assertForbidden();
        $this->report('margin', self::RANGE)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAsAdminWith('reports.inventory');
        $this->report('inventory')->assertOk();
        $this->report('inventory-movements', self::RANGE)->assertOk();
        $this->report('sales', self::RANGE)->assertForbidden();
    }

    public function test_csv_requires_reports_export(): void
    {
        $this->actingAsAdminWith('reports.view');

        $this->report('sales', [...self::RANGE, 'format' => 'csv'])->assertForbidden();
    }

    public function test_every_report_answers_with_the_common_envelope(): void
    {
        $this->actingAsAdminWith('reports.view');
        ProductVariant::factory()->withStock('5')->create();

        foreach (['sales', 'products', 'revenue', 'customers', 'inventory', 'inventory-movements', 'orders', 'shipping', 'margin', 'coupons'] as $report) {
            $this->report($report, self::RANGE)->assertOk()
                ->assertJsonStructure(['data' => ['report', 'period' => ['date_from', 'date_to', 'group_by', 'timezone'], 'filters', 'summary', 'rows', 'totals']])
                ->assertJsonPath('data.report', $report);
        }
        $this->report('unknown', self::RANGE)->assertNotFound();
    }

    public function test_date_range_validation(): void
    {
        $this->actingAsAdminWith('reports.view');

        $this->report('sales')->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'date_to']);
        $this->report('sales', ['date_from' => '01/09/2026', 'date_to' => '2026-09-30'])->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->report('sales', ['date_from' => '2026-09-30', 'date_to' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->report('sales', ['date_from' => '2025-01-01', 'date_to' => '2026-01-02'])->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->report('sales', ['date_from' => '2025-01-01', 'date_to' => '2026-01-01'])->assertOk(); // 366 days
        $this->report('sales', [...self::RANGE, 'group_by' => 'year'])->assertUnprocessable()->assertJsonValidationErrors('group_by');
        $this->report('products', [...self::RANGE, 'limit' => 501])->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->report('orders', [...self::RANGE, 'status' => 'lost'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->report('sales', [...self::RANGE, 'format' => 'xlsx'])->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->report('inventory')->assertOk();
        $this->report('inventory', ['date_from' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }
}
