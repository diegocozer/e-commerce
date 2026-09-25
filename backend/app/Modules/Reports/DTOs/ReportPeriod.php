<?php

declare(strict_types=1);

namespace App\Modules\Reports\DTOs;

use Carbon\CarbonImmutable;

/**
 * Inclusive date range expressed in the store timezone (America/Sao_Paulo) plus
 * the matching half-open UTC bounds used in SQL (`>= start AND < end`, index friendly).
 */
final readonly class ReportPeriod
{
    public const string TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $groupBy = null,
    ) {}

    public static function fromDates(string $dateFrom, string $dateTo, ?string $groupBy = null): self
    {
        return new self(
            CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, self::TIMEZONE),
            CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, self::TIMEZONE),
            $groupBy,
        );
    }

    /** Local "today" (store timezone). */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    public function startUtc(): string
    {
        return $this->from->startOfDay()->utc()->toIso8601String();
    }

    public function endUtcExclusive(): string
    {
        return $this->to->addDay()->startOfDay()->utc()->toIso8601String();
    }

    public function dateFrom(): string
    {
        return $this->from->toDateString();
    }

    public function dateTo(): string
    {
        return $this->to->toDateString();
    }

    /** @return array{date_from: string, date_to: string, group_by: string|null, timezone: string} */
    public function toArray(): array
    {
        return [
            'date_from' => $this->dateFrom(),
            'date_to' => $this->dateTo(),
            'group_by' => $this->groupBy,
            'timezone' => self::TIMEZONE,
        ];
    }
}
