<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Reports\Csv\CsvReportWriter;
use Illuminate\Support\Facades\DB;

final class CsvExportTest extends ReportsTestCase
{
    public function test_sales_csv_uses_pt_br_format(): void
    {
        $admin = $this->actingAsAdminWith('reports.view', 'reports.export');
        $this->paidOrder('2026-09-02 10:00', 123456, ['shipping_cents' => 0]);

        $response = $this->get('/api/v1/admin/reports/sales?date_from=2026-09-01&date_to=2026-09-02&format=csv')->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertDownload('sales_2026-09-01_2026-09-02.csv');
        $csv = $response->streamedContent();
        self::assertStringStartsWith(CsvReportWriter::BOM.'Período;"Pedidos pagos";', $csv);
        $lines = explode("\r\n", trim(substr($csv, 3)));
        self::assertCount(3, $lines);
        self::assertSame('01/09/2026;0;0;0,00;0,00;0,00;0,00;', $lines[1]);
        self::assertSame('02/09/2026;1;0;1234,56;1234,56;0,00;0,00;1234,56', $lines[2]);

        self::assertTrue(DB::table('audit_logs')->where('action', 'report.exported')->where('actor_id', $admin->id)->exists());
    }

    public function test_csv_cells_are_protected_against_formula_injection(): void
    {
        $this->actingAsAdminWith('reports.sales', 'reports.export');
        $variant = ProductVariant::factory()->create();
        $order = $this->paidOrder('2026-09-02 10:00', 1000);
        $this->item($order, $variant, '2', 1000, ['product_name' => '=HYPERLINK("http://x","y")', 'variant_name' => '@SUM(1)']);

        $csv = $this->get('/api/v1/admin/reports/products?date_from=2026-09-01&date_to=2026-09-30&format=csv')->assertOk()->streamedContent();

        self::assertStringContainsString('"\'=HYPERLINK(""http://x"",""y"")"', $csv);
        self::assertStringContainsString(";'@SUM(1);", $csv);
        self::assertStringContainsString(';2,000;10,00;1', $csv);
    }

    public function test_writer_escapes_and_formats(): void
    {
        $writer = new CsvReportWriter;

        self::assertSame("'-1+1", $writer->format('-1+1', 'text'));
        self::assertSame("'+55", $writer->format('+55', 'text'));
        self::assertSame('Vinil', $writer->format('Vinil', 'text'));
        self::assertSame('-0,05', $writer->format(-5, 'money'));
        self::assertSame('12,50', $writer->format(1250, 'bp'));
        self::assertSame('Sim', $writer->format(true, 'bool'));
        self::assertSame('', $writer->format(null, 'money'));
    }
}
