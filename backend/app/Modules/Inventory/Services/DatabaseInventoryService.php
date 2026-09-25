<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\InventoryRecords;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\Contracts\VariantLabelProvider;
use App\Modules\Inventory\DTOs\StockReservation;
use App\Modules\Inventory\Enums\InventoryMovementType as T;
use App\Modules\Inventory\Events\StockAdjusted;
use App\Modules\Inventory\Events\StockLow;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockAdjustment;
use App\Modules\Inventory\Exceptions\StaleStock;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;
use App\Shared\Domain\Quantity;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use stdClass;
use Throwable;

/**
 * ADR-008 / DATABASE.md §3.3 & §4: every balance change happens inside a
 * transaction, under SELECT ... FOR UPDATE ordered by variant_id, and writes
 * one immutable movement. The CHECK constraints are the last barrier.
 */
final class DatabaseInventoryService implements InventoryRecords, InventoryService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Container $container,
    ) {}

    public function availability(array $variantIds): array
    {
        $ids = self::normalizeIds($variantIds);
        $result = array_fill_keys($ids, Quantity::zero());
        if ($ids === []) {
            return $result;
        }

        foreach (DB::table('inventory')->whereIn('variant_id', $ids)->get(['variant_id', 'on_hand', 'reserved']) as $row) {
            $result[(int) $row->variant_id] = Quantity::fromString((string) $row->on_hand)
                ->subtract(Quantity::fromString((string) $row->reserved));
        }

        return $result;
    }

    public function lockForUpdate(array $variantIds): void
    {
        $this->lockRows(self::normalizeIds($variantIds));
    }

    public function reserve(StockReservation $r): void
    {
        $this->transaction(function () use ($r): void {
            $lines = self::sumLines($r);
            $rows = $this->lockRows(array_keys($lines));

            $shortages = [];
            foreach ($lines as $variantId => $quantity) {
                $available = isset($rows[$variantId]) ? self::available($rows[$variantId]) : Quantity::zero();
                if ($available->lessThan($quantity)) {
                    $shortages[$variantId] = [$quantity, Quantity::max($available, Quantity::zero())];
                }
            }
            if ($shortages !== []) {
                throw $this->insufficient($shortages);
            }

            foreach ($lines as $variantId => $quantity) {
                $this->apply($rows[$variantId], T::Reserve, $quantity, Quantity::zero(), $quantity, null, $r, ActorRef::system());
            }
        });
    }

    public function commit(StockReservation $r): void
    {
        $this->transaction(function () use ($r): void {
            $lines = self::sumLines($r);
            $rows = $this->lockRows(array_keys($lines));
            $outstanding = $this->referenceBalances($r, array_keys($lines));

            foreach ($lines as $variantId => $quantity) {
                // Idempotent: nothing left reserved for this reference → already committed/released.
                if (! isset($rows[$variantId]) || $outstanding[$variantId]['reserved']->lessThan($quantity)) {
                    continue;
                }
                $this->apply($rows[$variantId], T::Out, $quantity, $quantity->negate(), $quantity->negate(), null, $r, ActorRef::system());
            }
        });
    }

    public function release(StockReservation $r): void
    {
        $this->transaction(function () use ($r): void {
            $lines = self::sumLines($r);
            $rows = $this->lockRows(array_keys($lines));
            $outstanding = $this->referenceBalances($r, array_keys($lines));

            foreach ($lines as $variantId => $quantity) {
                if (! isset($rows[$variantId]) || $outstanding[$variantId]['reserved']->lessThan($quantity)) {
                    continue;
                }
                $this->apply($rows[$variantId], T::Release, $quantity, Quantity::zero(), $quantity->negate(), null, $r, ActorRef::system());
            }
        });
    }

    public function restock(StockReservation $r): void
    {
        $this->transaction(function () use ($r): void {
            $lines = self::sumLines($r);
            $rows = $this->lockRows(array_keys($lines));
            $outstanding = $this->referenceBalances($r, array_keys($lines));

            foreach ($lines as $variantId => $quantity) {
                if (! isset($rows[$variantId]) || $outstanding[$variantId]['shipped']->lessThan($quantity)) {
                    continue;
                }
                $this->apply($rows[$variantId], T::Return, $quantity, $quantity, Quantity::zero(), null, $r, ActorRef::system());
            }
        });
    }

    public function receive(int $variantId, Quantity $q, string $reason, ActorRef $actor): void
    {
        if (! $q->isPositive()) {
            throw new InvalidArgumentException('Receipt quantity must be positive.');
        }
        $reason = self::reason($reason);

        $this->transaction(function () use ($variantId, $q, $reason, $actor): void {
            $this->ensureRecord($variantId);
            $row = $this->lockRows([$variantId])[$variantId];
            $movement = $this->apply($row, T::In, $q, $q, Quantity::zero(), $reason, null, $actor);
            StockAdjusted::dispatch($variantId, $movement->id, T::In->value, $movement->on_hand_after->toDecimalString(), $movement->admin_user_id);
        });
    }

    public function adjust(int $variantId, Quantity $newOnHand, string $reason, ActorRef $actor, ?Quantity $expectedOnHand = null): void
    {
        if ($newOnHand->isNegative()) {
            throw InvalidStockAdjustment::because('O novo saldo não pode ser negativo.');
        }
        $reason = self::reason($reason);

        $this->transaction(function () use ($variantId, $newOnHand, $reason, $actor, $expectedOnHand): void {
            $this->ensureRecord($variantId);
            $row = $this->lockRows([$variantId])[$variantId];
            $onHand = Quantity::fromString((string) $row->on_hand);
            $reserved = Quantity::fromString((string) $row->reserved);

            if ($expectedOnHand !== null && ! $expectedOnHand->equals($onHand)) {
                throw StaleStock::currentIs($onHand->toDecimalString());
            }
            if ($newOnHand->lessThan($reserved)) {
                throw InvalidStockAdjustment::because('Existem '.$reserved->format(3).' reservados em pedidos pendentes.');
            }
            if ($newOnHand->equals($onHand)) {
                throw InvalidStockAdjustment::because('O novo saldo é igual ao saldo atual.');
            }

            $delta = $newOnHand->subtract($onHand);
            $movement = $this->apply($row, T::Adjust, $delta->abs(), $delta, Quantity::zero(), $reason, null, $actor);
            StockAdjusted::dispatch($variantId, $movement->id, T::Adjust->value, $movement->on_hand_after->toDecimalString(), $movement->admin_user_id);
        });
    }

    public function ensureRecord(int $variantId, ?Quantity $lowStockThreshold = null): void
    {
        DB::table('inventory')->insertOrIgnore([
            'variant_id' => $variantId,
            'on_hand' => '0',
            'reserved' => '0',
            'low_stock_threshold' => $lowStockThreshold?->toDecimalString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function setLowStockThreshold(int $variantId, ?Quantity $threshold, ActorRef $actor): void
    {
        if ($threshold !== null && $threshold->isNegative()) {
            throw new InvalidArgumentException('Threshold must be non-negative.');
        }

        $this->transaction(function () use ($variantId, $threshold): void {
            $this->ensureRecord($variantId);
            $row = $this->lockRows([$variantId])[$variantId];
            DB::table('inventory')->where('id', $row->id)->update([
                'low_stock_threshold' => $threshold?->toDecimalString(),
                'updated_at' => now(),
            ]);
            $row->low_stock_threshold = $threshold?->toDecimalString();
            $this->checkLowStock($row);
        });
    }

    public function defaultLowStockThreshold(): Quantity
    {
        $value = $this->settings->get(SettingKey::InventoryDefaultLowStockThreshold);

        try {
            return Quantity::fromNumeric(is_int($value) || is_float($value) ? $value : (string) $value);
        } catch (Throwable) {
            return Quantity::ofUnits(10);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @param  list<int>  $ids
     * @return array<int, stdClass> variant_id => locked row
     */
    private function lockRows(array $ids): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Inventory locks require an open database transaction.');
        }
        sort($ids);
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('inventory')
            ->whereIn('variant_id', $ids)
            ->orderBy('variant_id')
            ->lockForUpdate()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->variant_id] = $row;
        }

        return $out;
    }

    private function apply(stdClass $row, T $type, Quantity $quantity, Quantity $onHandDelta, Quantity $reservedDelta, ?string $reason, ?StockReservation $ref, ActorRef $actor): InventoryMovement
    {
        $onHand = Quantity::fromString((string) $row->on_hand)->add($onHandDelta);
        $reserved = Quantity::fromString((string) $row->reserved)->add($reservedDelta);

        if ($onHand->isNegative() || $reserved->isNegative() || $reserved->greaterThan($onHand)) {
            throw $this->insufficient([(int) $row->variant_id => [$quantity, self::available($row)]]);
        }

        DB::table('inventory')->where('id', $row->id)->update([
            'on_hand' => $onHand->toDecimalString(),
            'reserved' => $reserved->toDecimalString(),
            'updated_at' => now(),
        ]);
        $row->on_hand = $onHand->toDecimalString();
        $row->reserved = $reserved->toDecimalString();

        $movement = new InventoryMovement([
            'variant_id' => (int) $row->variant_id,
            'type' => $type,
            'quantity' => $quantity,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'on_hand_after' => $onHand,
            'reserved_after' => $reserved,
            'reason' => $reason,
            'reference_type' => $ref?->referenceType,
            'reference_id' => $ref?->referenceId,
            'admin_user_id' => $actor->type === ActorType::Admin ? $actor->id : null,
        ]);
        $movement->save();

        $this->checkLowStock($row);

        return $movement;
    }

    /** RN-EST-010: one alert per variant until available goes back above the threshold. */
    private function checkLowStock(stdClass $row): void
    {
        $threshold = $row->low_stock_threshold !== null
            ? Quantity::fromString((string) $row->low_stock_threshold)
            : $this->defaultLowStockThreshold();
        $available = self::available($row);
        $isLow = $available->lessThanOrEqual($threshold);

        if ($isLow && $row->low_stock_alerted_at === null) {
            $now = now();
            DB::table('inventory')->where('id', $row->id)->update(['low_stock_alerted_at' => $now]);
            $row->low_stock_alerted_at = $now;
            StockLow::dispatch((int) $row->variant_id, $available->toDecimalString(), $threshold->toDecimalString());
        } elseif (! $isLow && $row->low_stock_alerted_at !== null) {
            DB::table('inventory')->where('id', $row->id)->update(['low_stock_alerted_at' => null]);
            $row->low_stock_alerted_at = null;
        }
    }

    /**
     * Outstanding quantities of the reference per variant:
     * reserved = reserve − release − out; shipped = out − return.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{reserved: Quantity, shipped: Quantity}>
     */
    private function referenceBalances(StockReservation $r, array $variantIds): array
    {
        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = ['reserved' => Quantity::zero(), 'shipped' => Quantity::zero()];
        }

        $rows = DB::table('inventory_movements')
            ->where('reference_type', $r->referenceType)
            ->where('reference_id', $r->referenceId)
            ->whereIn('variant_id', $variantIds)
            ->groupBy('variant_id', 'type')
            ->selectRaw('variant_id, type, sum(quantity) as total')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->variant_id;
            $q = Quantity::fromString((string) $row->total);
            match ($row->type) {
                T::Reserve->value => $result[$id]['reserved'] = $result[$id]['reserved']->add($q),
                T::Release->value => $result[$id]['reserved'] = $result[$id]['reserved']->subtract($q),
                T::Out->value => [
                    $result[$id]['reserved'] = $result[$id]['reserved']->subtract($q),
                    $result[$id]['shipped'] = $result[$id]['shipped']->add($q),
                ],
                T::Return->value => $result[$id]['shipped'] = $result[$id]['shipped']->subtract($q),
                default => null,
            };
        }

        // Commit without a previous reservation of this reference (e.g. late payment path
        // re-reserves first) is handled by reserve(); nothing else to do here.
        return $result;
    }

    /** @param  array<int, array{0: Quantity, 1: Quantity}>  $shortages */
    private function insufficient(array $shortages): InsufficientStock
    {
        $labels = [];
        if ($this->container->bound(VariantLabelProvider::class)) {
            try {
                $labels = $this->container->make(VariantLabelProvider::class)->labels(array_keys($shortages));
            } catch (Throwable) {
                $labels = [];
            }
        }

        $items = [];
        foreach ($shortages as $variantId => [$requested, $available]) {
            $items[] = [
                'cart_item_id' => null,
                'variant_id' => $variantId,
                'sku' => $labels[$variantId]['sku'] ?? '',
                'product_name' => $labels[$variantId]['product_name'] ?? '',
                'requested_quantity' => $requested->toNumber(),
                'available_quantity' => $available->toNumber(),
            ];
        }

        return InsufficientStock::forItems($items);
    }

    /** @return array<int, Quantity> variant => summed quantity (ordered by variant id) */
    private static function sumLines(StockReservation $r): array
    {
        $sum = [];
        foreach ($r->lines as $line) {
            if (! $line->quantity->isPositive()) {
                throw new InvalidArgumentException('Stock line quantities must be positive.');
            }
            $sum[$line->variantId] = isset($sum[$line->variantId]) ? $sum[$line->variantId]->add($line->quantity) : $line->quantity;
        }
        ksort($sum);

        return $sum;
    }

    private static function available(stdClass $row): Quantity
    {
        return Quantity::fromString((string) $row->on_hand)->subtract(Quantity::fromString((string) $row->reserved));
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw InvalidStockAdjustment::because('Informe um motivo entre 3 e 255 caracteres.', 'reason');
        }

        return $reason;
    }

    /**
     * @template R
     *
     * @param  callable(): R  $callback
     * @return R
     */
    private function transaction(callable $callback): mixed
    {
        // Nested calls (inside the caller's transaction) use a savepoint; retries
        // on deadlock/serialization failure happen only at the outermost level.
        return DB::transaction($callback, DB::transactionLevel() === 0 ? 3 : 1);
    }
}
