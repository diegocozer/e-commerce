<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderNumberGenerator;
use App\Modules\Payments\Models\Payment;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Database-level guarantees of DATABASE.md §4.4 that the application relies on. */
final class ConstraintsTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_checks_reject_negative_and_reserved_above_on_hand(): void
    {
        $variant = ProductVariant::factory()->withStock('10')->create();
        $id = Inventory::query()->where('variant_id', $variant->id)->value('id');

        $this->assertViolates('inventory_on_hand_non_negative_check', fn () => DB::update('update inventory set on_hand = -1 where id = ?', [$id]));
        $this->assertViolates('inventory_reserved_non_negative_check', fn () => DB::update('update inventory set reserved = -1 where id = ?', [$id]));
        $this->assertViolates('inventory_reserved_lte_on_hand_check', fn () => DB::update('update inventory set reserved = 11 where id = ?', [$id]));

        DB::update('update inventory set reserved = 10 where id = ?', [$id]);
        self::assertSame('0.000', Inventory::query()->findOrFail($id)->available()->toDecimalString());
    }

    public function test_inventory_movement_type_must_match_deltas_and_is_append_only(): void
    {
        $movement = InventoryMovement::factory()->create();

        $this->assertViolates('inventory_movements_type_delta_check', fn () => InventoryMovement::factory()->create([
            'type' => 'reserve', 'quantity' => '1', 'on_hand_delta' => '1', 'reserved_delta' => '0',
        ]));
        $this->assertViolates('append-only', fn () => DB::update('update inventory_movements set reason = ? where id = ?', ['x', $movement->id]));
        $this->assertViolates('append-only', fn () => DB::delete('delete from inventory_movements where id = ?', [$movement->id]));
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = AuditLog::factory()->create();

        $this->assertViolates('audit_logs is append-only', fn () => DB::update("update audit_logs set action = 'x' where id = ?", [$log->id]));
        $this->assertViolates('audit_logs is append-only', fn () => DB::delete('delete from audit_logs where id = ?', [$log->id]));
        self::assertSame(1, AuditLog::query()->count());
    }

    public function test_slug_uniqueness_ignores_soft_deleted_rows(): void
    {
        $category = Category::factory()->create(['slug' => 'lonas']);

        $this->assertViolates('categories_slug_active_unique', fn () => Category::factory()->create(['slug' => 'lonas']));

        $category->delete();
        $again = Category::factory()->create(['slug' => 'lonas']);
        self::assertNotSame($category->id, $again->id);

        $this->assertViolates('categories_slug_format_check', fn () => Category::factory()->create(['slug' => 'Lonas Grandes']));
    }

    public function test_order_number_sequence_formatting(): void
    {
        $generator = app(OrderNumberGenerator::class);
        $first = $generator->next();
        $second = $generator->next();

        self::assertMatchesRegularExpression('/^CV-\d{6,}$/', $first);
        self::assertSame((int) substr($first, 3) + 1, (int) substr($second, 3));
        self::assertSame('CV-000123', OrderNumberGenerator::format('CV-', 123));
        self::assertSame('CV-1000000', OrderNumberGenerator::format('CV-', 1000000));

        $this->assertViolates('orders_number_format_check', fn () => Order::factory()->create(['number' => 'CV-12']));
    }

    public function test_order_invariants(): void
    {
        $this->assertViolates('orders_total_check', fn () => Order::factory()->create(['total_cents' => 1]));
        $this->assertViolates('orders_pickup_statuses_check', fn () => Order::factory()->paid()->create(['status' => 'ready_for_pickup']));

        $order = Order::factory()->pickup()->create();
        self::assertSame('pickup', $order->shipping_method_type);

        $item = OrderItem::factory()->create();
        $this->assertViolates('order_items is append-only', fn () => DB::update('update order_items set sku = ? where id = ?', ['X', $item->id]));
    }

    public function test_one_active_payment_per_order(): void
    {
        $payment = Payment::factory()->create();

        $this->assertViolates('payments_order_active_unique', fn () => Payment::factory()->create(['order_id' => $payment->order_id]));

        DB::update("update payments set status = 'failed' where id = ?", [$payment->id]);
        Payment::factory()->create(['order_id' => $payment->order_id]);
        self::assertSame(2, Payment::query()->where('order_id', $payment->order_id)->count());
    }

    public function test_cart_item_shape_and_merge_key(): void
    {
        $item = CartItem::factory()->create();

        $this->assertViolates('cart_items_shape_check', fn () => CartItem::factory()->create(['quantity' => '1', 'width_mm' => 100]));
        $this->assertViolates('cart_items_merge_unique', fn () => CartItem::factory()->create(['cart_id' => $item->cart_id, 'variant_id' => $item->variant_id]));

        $area = CartItem::factory()->dimensions(1200, 2500, 2)->create();
        self::assertNull($area->quantity);
        self::assertSame(1200, $area->dimensions()?->widthMm);
    }

    /** Runs the statement in a savepoint so the surrounding test transaction stays usable. */
    private function assertViolates(string $expectedMessage, Closure $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());

            return;
        }

        self::fail("Expected a database violation containing [{$expectedMessage}].");
    }
}
