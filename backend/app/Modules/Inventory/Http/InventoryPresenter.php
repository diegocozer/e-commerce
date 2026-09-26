<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Inventory\Contracts\MovementReferenceResolver;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** API.md §2.13 InventoryItem / InventoryMovement. */
final class InventoryPresenter
{
    public function __construct(
        private readonly VariantLabelProvider $labels,
        private readonly MovementReferenceResolver $references,
        private readonly InventoryRecords $records,
    ) {}

    public static function ts(?DateTimeInterface $d): ?string
    {
        return $d === null ? null : CarbonImmutable::instance($d)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param  Collection<int, Inventory>  $rows
     * @return list<array<string, mixed>>
     */
    public function items(Collection $rows): array
    {
        $labels = $this->labels->labels($rows->pluck('variant_id')->all());
        $default = $this->records->defaultLowStockThreshold();

        return $rows->map(function (Inventory $inv) use ($labels, $default) {
            $label = $labels[$inv->variant_id] ?? null;
            $unit = $label !== null ? SaleUnit::from($label['sale_unit']) : null;
            $threshold = $inv->low_stock_threshold ?? $default;
            $available = $inv->available();

            return [
                'variant_id' => $inv->variant_id,
                'sku' => $label['sku'] ?? '',
                'variant_name' => $label['name'] ?? '',
                'product' => $label !== null ? ['id' => $label['product_id'], 'name' => $label['product_name'], 'slug' => $label['product_slug'], 'is_active' => $label['product_is_active']] : null,
                'sale_unit' => $unit?->value,
                'stock_unit_abbr' => $unit?->abbreviation(),
                'on_hand' => $inv->on_hand->toNumber(),
                'reserved' => $inv->reserved->toNumber(),
                'available' => $available->toNumber(),
                'low_stock_threshold' => $threshold->toNumber(),
                'low_stock_threshold_override' => $inv->low_stock_threshold?->toNumber(),
                'is_low_stock' => $available->lessThanOrEqual($threshold),
                'low_stock_alerted_at' => self::ts($inv->low_stock_alerted_at),
                'updated_at' => self::ts($inv->updated_at),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, InventoryMovement>  $rows
     * @return list<array<string, mixed>>
     */
    public function movements(Collection $rows): array
    {
        $refs = $rows->filter(fn (InventoryMovement $m) => $m->reference_type !== null)
            ->map(fn (InventoryMovement $m) => ['type' => (string) $m->reference_type, 'id' => (int) $m->reference_id])->unique(fn ($r) => $r['type'].':'.$r['id'])->values()->all();
        $resolved = $refs === [] ? [] : $this->references->resolve($refs);
        $admins = DB::table('admin_users')->whereIn('id', $rows->pluck('admin_user_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(function (InventoryMovement $m) use ($resolved, $admins) {
            $key = $m->reference_type !== null ? $m->reference_type.':'.$m->reference_id : null;

            return [
                'id' => $m->id,
                'variant_id' => $m->variant_id,
                'type' => $m->type->value,
                'quantity' => $m->quantity->toNumber(),
                'on_hand_delta' => $m->on_hand_delta->toNumber(),
                'reserved_delta' => $m->reserved_delta->toNumber(),
                'on_hand_after' => $m->on_hand_after->toNumber(),
                'reserved_after' => $m->reserved_after->toNumber(),
                'reason' => $m->reason,
                'reference' => $key !== null ? ['type' => $m->reference_type, 'id' => (int) $m->reference_id, 'label' => $resolved[$key]['label'] ?? '#'.$m->reference_id] : null,
                'actor' => $m->admin_user_id !== null
                    ? ['type' => 'admin', 'id' => $m->admin_user_id, 'name' => $admins[$m->admin_user_id] ?? null]
                    : ['type' => 'system', 'id' => null, 'name' => null],
                'created_at' => self::ts($m->created_at),
            ];
        })->values()->all();
    }

    public static function quantity(Quantity $q): int|float
    {
        return $q->toNumber();
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<mixed>  $data
     * @return array<string, mixed>
     */
    public static function paginated($paginator, array $data): array
    {
        $b = $paginator->toArray();

        return [
            'data' => $data,
            'links' => ['first' => $b['first_page_url'], 'last' => $b['last_page_url'], 'prev' => $b['prev_page_url'], 'next' => $b['next_page_url']],
            'meta' => [
                'current_page' => $b['current_page'], 'from' => $b['from'], 'last_page' => $b['last_page'], 'path' => $b['path'],
                'per_page' => $b['per_page'], 'to' => $b['to'], 'total' => $b['total'], 'links' => $b['links'],
            ],
        ];
    }
}
