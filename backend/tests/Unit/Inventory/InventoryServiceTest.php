<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockReservation;
use App\Modules\Inventory\Events\StockLow;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Exceptions\InvalidStockAdjustment;
use App\Modules\Inventory\Exceptions\StaleStock;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** RN-EST / ADR-008 — BUSINESS_RULES §4.4.2 step-by-step example. */
final class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(InventoryService::class);
    }

    private static function q(string $v): Quantity
    {
        return Quantity::fromString($v);
    }

    private static function res(int $orderId, int $variantId, string $q): StockReservation
    {
        return new StockReservation('order', $orderId, [new StockLine($variantId, self::q($q))]);
    }

    /** @return array{string, string} */
    private function balance(int $variantId): array
    {
        $inv = Inventory::query()->where('variant_id', $variantId)->firstOrFail();

        return [$inv->on_hand->toDecimalString(), $inv->reserved->toDecimalString()];
    }

    public function test_step_by_step_example(): void
    {
        $v = ProductVariant::factory()->withStock('100')->create()->id;
        $admin = AdminUser::factory()->create();

        $this->svc->reserve(self::res(1, $v, '5'));
        $this->svc->reserve(self::res(2, $v, '10'));
        self::assertSame(['100.000', '15.000'], $this->balance($v));
        $this->svc->commit(self::res(1, $v, '5'));
        $this->svc->commit(self::res(1, $v, '5')); // idempotent
        self::assertSame(['95.000', '10.000'], $this->balance($v));
        $this->svc->release(self::res(2, $v, '10'));
        $this->svc->release(self::res(2, $v, '10')); // idempotent
        self::assertSame(['95.000', '0.000'], $this->balance($v));
        $this->svc->receive($v, self::q('50'), 'Entrada NF 4521', ActorRef::admin($admin->id));
        self::assertSame(['145.000', '0.000'], $this->balance($v));
        $this->svc->reserve(self::res(3, $v, '20'));
        $this->svc->commit(self::res(3, $v, '20'));
        self::assertSame(['125.000', '0.000'], $this->balance($v));
        $this->svc->restock(self::res(3, $v, '20'));
        $this->svc->restock(self::res(3, $v, '20')); // idempotent
        self::assertSame(['145.000', '0.000'], $this->balance($v));
        $this->svc->adjust($v, self::q('143.5'), 'perda por emenda', ActorRef::admin($admin->id));
        self::assertSame(['143.500', '0.000'], $this->balance($v));

        $types = InventoryMovement::query()->where('variant_id', $v)->orderBy('id')->pluck('type')->map->value->all();
        self::assertSame(['reserve', 'reserve', 'out', 'release', 'in', 'reserve', 'out', 'return', 'adjust'], $types);
        $adjust = InventoryMovement::query()->where('type', 'adjust')->firstOrFail();
        self::assertSame('-1.500', $adjust->on_hand_delta->toDecimalString());
        self::assertSame('1.500', $adjust->quantity->toDecimalString());
        self::assertSame($admin->id, $adjust->admin_user_id);
    }

    public function test_reserve_sums_lines_of_same_variant_and_is_all_or_nothing(): void
    {
        $a = ProductVariant::factory()->withStock('10')->create()->id;
        $b = ProductVariant::factory()->withStock('3')->create()->id;

        try {
            $this->svc->reserve(new StockReservation('order', 9, [
                new StockLine($a, self::q('4')), new StockLine($b, self::q('2')), new StockLine($b, self::q('2')),
            ]));
            self::fail('Expected InsufficientStock');
        } catch (InsufficientStock $e) {
            self::assertSame(409, $e->httpStatus());
            self::assertSame('insufficient_stock', $e->errorCode());
            self::assertSame($b, $e->items()[0]['variant_id']);
            self::assertSame(4, $e->items()[0]['requested_quantity']);
            self::assertSame(3, $e->items()[0]['available_quantity']);
            self::assertNotSame('', $e->items()[0]['sku']);
        }
        self::assertSame(['10.000', '0.000'], $this->balance($a));
    }

    public function test_variant_without_inventory_row_is_unavailable(): void
    {
        $v = ProductVariant::factory()->create()->id;
        self::assertSame(0, $this->svc->availability([$v])[$v]->milli());
        $this->expectException(InsufficientStock::class);
        $this->svc->reserve(self::res(1, $v, '1'));
    }

    public function test_adjust_rules(): void
    {
        $v = ProductVariant::factory()->withStock('10', '4')->create()->id;
        $actor = ActorRef::admin(AdminUser::factory()->create()->id);

        foreach ([['3', null, InvalidStockAdjustment::class], ['10', null, InvalidStockAdjustment::class], ['12', '9', StaleStock::class]] as [$new, $expected, $class]) {
            try {
                $this->svc->adjust($v, self::q($new), 'contagem', $actor, $expected !== null ? self::q($expected) : null);
                self::fail("Expected {$class}");
            } catch (InvalidStockAdjustment|StaleStock $e) {
                self::assertInstanceOf($class, $e);
            }
        }
        $this->svc->adjust($v, self::q('12'), 'contagem', $actor, self::q('10'));
        self::assertSame(['12.000', '4.000'], $this->balance($v));
    }

    public function test_low_stock_event_once_until_recovered(): void
    {
        Event::fake([StockLow::class]);
        $variant = ProductVariant::factory()->withStock('20')->create();
        Inventory::query()->where('variant_id', $variant->id)->update(['low_stock_threshold' => '10']);

        $this->svc->reserve(self::res(1, $variant->id, '10')); // available 10 ≤ 10
        $this->svc->reserve(self::res(2, $variant->id, '1'));
        Event::assertDispatchedTimes(StockLow::class, 1);
        $this->svc->receive($variant->id, self::q('50'), 'reposição', ActorRef::system());
        self::assertNull(Inventory::query()->where('variant_id', $variant->id)->value('low_stock_alerted_at'));
        $this->svc->reserve(self::res(3, $variant->id, '55'));
        Event::assertDispatchedTimes(StockLow::class, 2);
    }

    public function test_movements_are_immutable(): void
    {
        $v = ProductVariant::factory()->withStock('5')->create()->id;
        $this->svc->receive($v, self::q('1'), 'entrada', ActorRef::system());
        $this->expectException(QueryException::class);
        DB::table('inventory_movements')->where('variant_id', $v)->update(['reason' => 'x']);
    }

    public function test_lock_requires_transaction_and_locks_in_order(): void
    {
        $a = ProductVariant::factory()->withStock('1')->create()->id;
        $b = ProductVariant::factory()->withStock('1')->create()->id;
        DB::enableQueryLog();
        DB::transaction(fn () => $this->svc->lockForUpdate([$b, $a]));
        $sql = collect(DB::getQueryLog())->pluck('query')->first(fn ($q) => str_contains($q, 'for update'));
        self::assertStringContainsString('order by "variant_id" asc for update', $sql);
    }
}
