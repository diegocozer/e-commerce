<?php

declare(strict_types=1);

namespace App\Modules\Reports\Queries;

use App\Modules\Reports\Contracts\Report;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

abstract class AbstractReport implements Report
{
    public function permissions(): array
    {
        return ['reports.view'];
    }

    public function requiresPeriod(): bool
    {
        return true;
    }

    public function supportsGrouping(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [];
    }

    protected function db(): ConnectionInterface
    {
        return DB::connection();
    }

    protected function table(string $table): Builder
    {
        return $this->db()->table($table);
    }

    /** order_items aliased "oi". */
    public function baseItems(): Builder
    {
        return $this->table('order_items as oi');
    }
}
