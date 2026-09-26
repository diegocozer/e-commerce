<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Admin;

use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use App\Modules\Inventory\Enums\InventoryMovementType;
use App\Modules\Inventory\Http\InventoryPresenter;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Support\RecordsAudit;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** API.md §3.G.8 — every balance change goes through InventoryService. */
final class InventoryController
{
    use RecordsAudit;

    private const string DECIMAL = '/^\d{1,9}(\.\d{1,3})?$/';

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InventoryRecords $records,
        private readonly VariantLabelProvider $labels,
        private readonly InventoryPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'q' => ['sometimes', 'string', 'min:1', 'max:100'],
            'category_id' => ['sometimes', 'integer'],
            'brand_id' => ['sometimes', 'integer'],
            'sale_unit' => ['sometimes', Rule::enum(SaleUnit::class)],
            'low_stock' => ['sometimes', 'boolean'],
            'product_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['sku', '-sku', 'available', '-available', 'updated_at', '-updated_at'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $sort = $f['sort'] ?? 'sku';
        $catalogFilters = array_intersect_key($f, array_flip(['q', 'category_id', 'brand_id', 'sale_unit', 'product_active']));
        $ids = $this->labels->search($catalogFilters); // ordered by SKU; excludes deleted variants

        $query = Inventory::query()->whereIn('variant_id', $ids === [] ? [0] : $ids);
        if ($request->boolean('low_stock')) {
            $query->whereRaw('(on_hand - reserved) <= coalesce(low_stock_threshold, ?)', [$this->records->defaultLowStockThreshold()->toDecimalString()]);
        }
        match (ltrim($sort, '-')) {
            'sku' => $query->orderByRaw('array_position(ARRAY['.implode(',', array_map('intval', $ids ?: [0])).']::bigint[], variant_id) '.(str_starts_with($sort, '-') ? 'DESC' : 'ASC')),
            'available' => $query->orderByRaw('(on_hand - reserved) '.(str_starts_with($sort, '-') ? 'DESC' : 'ASC')),
            default => $query->orderBy('updated_at', str_starts_with($sort, '-') ? 'desc' : 'asc'),
        };
        $page = $query->orderBy('variant_id')->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(InventoryPresenter::paginated($page, $this->presenter->items($page->getCollection())));
    }

    public function show(int $variantId): JsonResponse
    {
        return new JsonResponse(['data' => $this->item($variantId)]);
    }

    public function update(Request $request, int $variantId): JsonResponse
    {
        $this->normalize($request, ['low_stock_threshold']);
        $data = $request->validate(['low_stock_threshold' => ['present', 'nullable', 'string', 'regex:'.self::DECIMAL]]);
        $row = Inventory::query()->where('variant_id', $variantId)->firstOrFail();
        $before = $row->low_stock_threshold?->toDecimalString();
        $threshold = $data['low_stock_threshold'] !== null ? Quantity::fromString($data['low_stock_threshold']) : null;
        $this->records->setLowStockThreshold($variantId, $threshold, ActorRef::current());
        $this->audit('inventory.threshold_updated', 'inventory', $row->id, ['low_stock_threshold' => $before], ['low_stock_threshold' => $threshold?->toDecimalString()]);

        return new JsonResponse(['data' => $this->item($variantId)]);
    }

    public function entries(Request $request, int $variantId): JsonResponse
    {
        $row = Inventory::query()->where('variant_id', $variantId)->first();
        $label = $this->labels->labels([$variantId])[$variantId] ?? null;
        abort_if($label === null, 404);
        $this->normalize($request, ['quantity']);
        $data = $request->validate([
            'quantity' => ['required', 'string', 'regex:'.self::DECIMAL],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $q = Quantity::fromString($data['quantity']);
        if (! $q->isPositive() || $q->greaterThan(Quantity::ofUnits(100000))) {
            throw ValidationException::withMessages(['quantity' => ['Informe uma quantidade entre 0,001 e 100.000.']]);
        }
        if (! SaleUnit::from($label['sale_unit'])->allowsFraction() && $label['sale_unit'] !== SaleUnit::SquareMeter->value && ! $q->isInteger()) {
            throw ValidationException::withMessages(['quantity' => ['Quantidade deve ser inteira para esta unidade.']]);
        }
        $movement = DB::transaction(function () use ($variantId, $q, $data, $row) {
            $this->inventory->receive($variantId, $q, $data['reason'], ActorRef::current());
            $movement = InventoryMovement::query()->where('variant_id', $variantId)->latest('id')->firstOrFail();
            $this->audit('inventory.entry', 'inventory', $row?->id ?? Inventory::query()->where('variant_id', $variantId)->value('id'), [], ['quantity' => $q->toDecimalString(), 'reason' => $data['reason']]);

            return $movement;
        });

        return new JsonResponse(['data' => ['movement' => $this->presenter->movements(collect([$movement]))[0], 'inventory' => $this->item($variantId)]], 201);
    }

    public function adjustments(Request $request, int $variantId): JsonResponse
    {
        abort_if(($this->labels->labels([$variantId])[$variantId] ?? null) === null, 404);
        $this->normalize($request, ['new_on_hand', 'expected_on_hand']);
        $data = $request->validate([
            'new_on_hand' => ['required', 'string', 'regex:'.self::DECIMAL],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'expected_on_hand' => ['sometimes', 'nullable', 'string', 'regex:'.self::DECIMAL],
        ]);
        $movement = DB::transaction(function () use ($variantId, $data) {
            $this->inventory->adjust(
                $variantId,
                Quantity::fromString($data['new_on_hand']),
                $data['reason'],
                ActorRef::current(),
                isset($data['expected_on_hand']) ? Quantity::fromString($data['expected_on_hand']) : null,
            );
            $movement = InventoryMovement::query()->where('variant_id', $variantId)->latest('id')->firstOrFail();
            $this->audit('inventory.adjusted', 'inventory', (int) Inventory::query()->where('variant_id', $variantId)->value('id'),
                ['on_hand' => $movement->on_hand_after->subtract($movement->on_hand_delta)->toDecimalString()],
                ['on_hand' => $movement->on_hand_after->toDecimalString(), 'reason' => $data['reason']]);

            return $movement;
        });

        return new JsonResponse(['data' => ['movement' => $this->presenter->movements(collect([$movement]))[0], 'inventory' => $this->item($variantId)]], 201);
    }

    public function movements(Request $request, ?int $variantId = null): JsonResponse|StreamedResponse
    {
        $f = $request->validate([
            'type' => ['sometimes', 'string'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
            'order_id' => ['sometimes', 'integer'],
            'variant_id' => ['sometimes', 'integer'],
            'actor_id' => ['sometimes', 'integer'],
            'sort' => ['sometimes', Rule::in(['-created_at', 'created_at'])],
            'format' => ['sometimes', Rule::in(['json', 'csv'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $types = isset($f['type']) ? array_filter(explode(',', $f['type'])) : [];
        foreach ($types as $t) {
            if (InventoryMovementType::tryFrom($t) === null) {
                throw ValidationException::withMessages(['type' => ['Tipo de movimento inválido.']]);
            }
        }
        if ($variantId !== null) {
            abort_unless(Inventory::query()->where('variant_id', $variantId)->exists() || InventoryMovement::query()->where('variant_id', $variantId)->exists(), 404);
        }
        $tz = 'America/Sao_Paulo';
        $query = InventoryMovement::query()
            ->when($variantId ?? ($f['variant_id'] ?? null), fn ($q, $id) => $q->where('variant_id', $id))
            ->when($types !== [], fn ($q) => $q->whereIn('type', $types))
            ->when(isset($f['date_from']), fn ($q) => $q->where('created_at', '>=', CarbonImmutable::parse($f['date_from'], $tz)->startOfDay()->utc()))
            ->when(isset($f['date_to']), fn ($q) => $q->where('created_at', '<=', CarbonImmutable::parse($f['date_to'], $tz)->endOfDay()->utc()))
            ->when(isset($f['order_id']), fn ($q) => $q->where('reference_type', 'order')->where('reference_id', $f['order_id']))
            ->when(isset($f['actor_id']), fn ($q) => $q->where('admin_user_id', $f['actor_id']))
            ->orderBy('created_at', ($f['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc')
            ->orderBy('id', ($f['sort'] ?? '-created_at') === 'created_at' ? 'asc' : 'desc');

        if (($f['format'] ?? 'json') === 'csv') {
            if (! $request->user('admin')?->can('reports.export')) {
                throw new AuthorizationException;
            }

            return $this->csv($query->limit(50000)->get());
        }
        $page = $query->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return new JsonResponse(InventoryPresenter::paginated($page, $this->presenter->movements($page->getCollection())));
    }

    /** @param  Collection<int, InventoryMovement>  $rows */
    private function csv($rows): StreamedResponse
    {
        $data = $this->presenter->movements($rows);
        $labels = $this->labels->labels($rows->pluck('variant_id')->unique()->values()->all());

        return response()->streamDownload(function () use ($data, $labels): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'sku', 'type', 'quantity', 'on_hand_delta', 'reserved_delta', 'on_hand_after', 'reserved_after', 'reason', 'reference', 'actor']);
            foreach ($data as $m) {
                $reason = (string) $m['reason'];
                if ($reason !== '' && in_array($reason[0], ['=', '+', '-', '@'], true)) {
                    $reason = "'".$reason; // CSV injection guard
                }
                fputcsv($out, [$m['id'], $m['created_at'], $labels[$m['variant_id']]['sku'] ?? '', $m['type'], $m['quantity'], $m['on_hand_delta'],
                    $m['reserved_delta'], $m['on_hand_after'], $m['reserved_after'], $reason, $m['reference']['label'] ?? '', $m['actor']['name'] ?? $m['actor']['type']]);
            }
            fclose($out);
        }, 'movimentos-estoque.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function item(int $variantId): array
    {
        $row = Inventory::query()->where('variant_id', $variantId)->firstOrFail();

        return $this->presenter->items(collect([$row]))[0];
    }

    /** @param  list<string>  $fields */
    private function normalize(Request $request, array $fields): void
    {
        foreach ($fields as $f) {
            $v = $request->input($f);
            if (is_int($v) || is_float($v)) {
                $s = is_int($v) ? (string) $v : var_export($v, true);
                $request->merge([$f => str_ends_with($s, '.0') ? substr($s, 0, -2) : $s]);
            }
        }
    }
}
