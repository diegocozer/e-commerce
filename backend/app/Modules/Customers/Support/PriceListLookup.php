<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use Illuminate\Support\Facades\DB;

/**
 * Read-only access to `price_lists` names (Customers cannot import Pricing,
 * which depends on Customers). Only id/code/name/is_default are read.
 */
final class PriceListLookup
{
    /** @var array<int, array{id:int, code:string, name:string}|null> */
    private array $cache = [];

    /** @return array{id:int, code:string, name:string}|null */
    public function find(?int $id): ?array
    {
        if ($id === null) {
            return null;
        }
        if (! array_key_exists($id, $this->cache)) {
            $row = DB::table('price_lists')->where('id', $id)->first(['id', 'code', 'name']);
            $this->cache[$id] = $row === null ? null : ['id' => (int) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name];
        }

        return $this->cache[$id];
    }

    public function defaultId(): ?int
    {
        $id = DB::table('price_lists')->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }
}
