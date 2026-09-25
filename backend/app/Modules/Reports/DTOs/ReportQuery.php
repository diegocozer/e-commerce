<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

/** Validated input of GET /admin/reports/{report} (API.md §3.G.15). */
final readonly class ReportQuery
{
    /** @param array<string, string|int|null> $filters */
    public function __construct(
        public ReportPeriod $period,
        public array $filters,
        public int $limit,
    ) {}

    public function filter(string $key): string|int|null
    {
        return $this->filters[$key] ?? null;
    }

    public function intFilter(string $key): ?int
    {
        $value = $this->filter($key);

        return $value === null ? null : (int) $value;
    }
}
