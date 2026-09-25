<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

/** Output of a report query: `ReportResponse<Row>` without the envelope (API.md §2.16). */
final readonly class ReportResult
{
    /**
     * @param  array<string, int|float|null>  $summary
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     */
    public function __construct(
        public array $summary,
        public array $rows,
        public ?array $totals = null,
    ) {}
}
