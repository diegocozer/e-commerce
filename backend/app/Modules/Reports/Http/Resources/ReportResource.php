<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Reports\DTOs\ReportQuery;
use App\Modules\Reports\DTOs\ReportResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `{ data: ReportResponse<Row> }` (API.md §2.16). */
final class ReportResource extends JsonResource
{
    public function __construct(private readonly string $report, private readonly ReportQuery $query, ReportResult $result)
    {
        parent::__construct($result);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ReportResult $result */
        $result = $this->resource;

        return [
            'report' => $this->report,
            'period' => $this->query->period->toArray(),
            'filters' => (object) $this->query->filters,
            'summary' => $result->summary,
            'rows' => $result->rows,
            'totals' => $result->totals,
        ];
    }
}
