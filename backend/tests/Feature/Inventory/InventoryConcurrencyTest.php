<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\Models\Inventory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RC-INV-01: real concurrency on PostgreSQL. Data is COMMITTED (no
 * RefreshDatabase transaction) so separate processes/connections see it;
 * tables are truncated afterwards.
 */
final class InventoryConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! RefreshDatabaseState::$migrated) {
            Artisan::call('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
    }

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE inventory_movements, inventory, product_variants, products, categories, brands RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    public function test_row_is_locked_for_update_until_commit(): void
    {
        $variant = ProductVariant::factory()->withStock('8')->create();
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $other = DB::connection('pgsql_race');

        DB::beginTransaction();
        app(InventoryService::class)->lockForUpdate([$variant->id]);
        try {
            $other->select('SELECT id FROM inventory WHERE variant_id = ? FOR UPDATE NOWAIT', [$variant->id]);
            self::fail('The row should be locked by the first connection.');
        } catch (QueryException $e) {
            self::assertSame('55P03', $e->getCode()); // lock_not_available
        } finally {
            DB::rollBack();
        }

        self::assertCount(1, $other->select('SELECT id FROM inventory WHERE variant_id = ? FOR UPDATE NOWAIT', [$variant->id]));
        $other->disconnect();
    }

    public function test_parallel_processes_never_oversell_the_last_units(): void
    {
        $variant = ProductVariant::factory()->withStock('8')->create();
        $barrier = sys_get_temp_dir().'/inv-barrier-'.uniqid();
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'LOG_STACK' => 'null',
        ]);

        $procs = [];
        foreach (range(1, 4) as $i) {
            $pipes = [];
            $procs[] = [proc_open([PHP_BINARY, __DIR__.'/Fixtures/reserve_worker.php', (string) $variant->id, (string) (100 + $i), '3', $barrier],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env), $pipes];
        }
        usleep(700_000); // let the workers boot and wait on the barrier
        touch($barrier);

        $results = [];
        foreach ($procs as [$proc, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1])).trim((string) stream_get_contents($pipes[2]));
            proc_close($proc);
        }
        @unlink($barrier);

        sort($results);
        self::assertSame(['INSUFFICIENT', 'INSUFFICIENT', 'OK', 'OK'], $results, implode(' | ', $results));
        $inventory = Inventory::query()->where('variant_id', $variant->id)->firstOrFail();
        self::assertSame('6.000', $inventory->reserved->toDecimalString());
        self::assertTrue($inventory->reserved->lessThanOrEqual($inventory->on_hand));
        self::assertSame(2, DB::table('inventory_movements')->where('variant_id', $variant->id)->where('type', 'reserve')->count());
    }
}
