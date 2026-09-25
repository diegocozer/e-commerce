<?php

declare(strict_types=1);

namespace App\Modules\Reports\Contracts;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;

/**
 * One read-only report of GET /admin/reports/{report} (ARCHITECTURE.md §2.4 Reports).
 * Implementations only SELECT from other modules' tables.
 */
interface Report
{
    /** Route name, e.g. "sales". */
    public function name(): string;

    /** @return list<string> permissions accepted (any of) — API.md §6.3 */
    public function permissions(): array;

    /** Whether date_from/date_to are required. */
    public function requiresPeriod(): bool;

    /** Whether group_by (day|week|month) applies. */
    public function supportsGrouping(): bool;

    /** @return list<string> extra query filters accepted by this report */
    public function filters(): array;

    public function run(ReportQuery $query): ReportResult;

    /**
     * CSV columns: row key => [pt-BR header, type]. Types: text, int, money, decimal3,
     * bp, date, datetime, bool.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function csvColumns(): array;
}
