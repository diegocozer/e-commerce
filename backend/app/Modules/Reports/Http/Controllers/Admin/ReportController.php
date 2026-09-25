<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers\Admin;

use App\Modules\Reports\Csv\CsvReportWriter;
use App\Modules\Reports\Http\Requests\Admin\ReportRequest;
use App\Modules\Reports\Http\Resources\ReportResource;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Domain\ActorRef;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /admin/reports/{report} (API.md §3.G.15): JSON or synchronous CSV download. */
final class ReportController
{
    public function show(ReportRequest $request, CsvReportWriter $writer, AuditLogger $audit): ReportResource|StreamedResponse
    {
        $report = $request->report();
        $query = $request->toReportQuery();
        $result = $report->run($query);

        if (! $request->isCsv()) {
            return new ReportResource($report->name(), $query, $result);
        }

        if (count($result->rows) > ReportRequest::CSV_MAX_ROWS) {
            throw ValidationException::withMessages(['date_from' => 'Período muito longo para exportação.']);
        }

        $audit->record(new AuditEntry(
            actor: ActorRef::admin((int) $request->user('admin')->getAuthIdentifier()),
            action: 'report.exported',
            newValues: [
                'report' => $report->name(),
                ...$query->period->toArray(),
                'filters' => array_filter($query->filters, static fn ($v): bool => $v !== null),
                'rows' => count($result->rows),
            ],
        ));

        $filename = sprintf('%s_%s_%s.csv', $report->name(), $query->period->dateFrom(), $query->period->dateTo());

        return response()->streamDownload(function () use ($writer, $report, $result): void {
            $out = fopen('php://output', 'wb');
            $writer->write($out, $report->csvColumns(), $result->rows);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
