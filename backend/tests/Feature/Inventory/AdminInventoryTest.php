<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\DTOs\StockLine;
use App\Modules\Inventory\DTOs\StockReservation;
use App\Shared\Domain\Quantity;
use Tests\Feature\Catalog\CatalogTestCase;

final class AdminInventoryTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function id(string $sku): int
    {
        return (int) ProductVariant::query()->where('sku', $sku)->value('id');
    }

    public function test_permissions(): void
    {
        $id = $this->id('ADJ-122');
        $this->actingAsAdminWith('products.view');
        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
        $this->actingAsAdminWith('inventory.view');
        $this->getJson('/api/v1/admin/inventory')->assertOk();
        $this->postJson("/api/v1/admin/inventory/{$id}/entries", ['quantity' => 1, 'reason' => 'NF 1'])->assertForbidden();
        $this->postJson("/api/v1/admin/inventory/{$id}/adjustments", ['new_on_hand' => 1, 'reason' => 'contagem'])->assertForbidden();
        $this->patchJson("/api/v1/admin/inventory/{$id}", ['low_stock_threshold' => 1])->assertForbidden();
        $this->getJson("/api/v1/admin/inventory/{$id}/movements?format=csv")->assertForbidden();
    }

    public function test_listing_filters_and_sort(): void
    {
        $this->actingAsAdminWith('inventory.view');
        $res = $this->getJson('/api/v1/admin/inventory?low_stock=1')->assertOk();
        self::assertContains('ADJ-122', array_column($res->json('data'), 'sku'));
        self::assertContains('TIN-ECO-1L-K', array_column($res->json('data'), 'sku'));
        $this->getJson('/api/v1/admin/inventory?q=VIN-BR&sort=-sku')->assertOk()
            ->assertJsonPath('data.0.sku', 'VIN-BR-122-FO')->assertJsonPath('data.0.stock_unit_abbr', 'm')->assertJsonPath('data.0.product.slug', 'vinil-adesivo-branco-122m');
        $this->getJson('/api/v1/admin/inventory?sale_unit=BOX&sort=-available')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.sku', 'PAP-A4-75-CX10');
        $this->getJson('/api/v1/admin/inventory?sort=bad')->assertStatus(422);
        $this->getJson('/api/v1/admin/inventory/'.$this->id('ADJ-122'))->assertOk()
            ->assertJsonPath('data.available', 8)->assertJsonPath('data.is_low_stock', true)->assertJsonPath('data.low_stock_threshold', 20);
    }

    public function test_entry_adjust_threshold_and_movements(): void
    {
        $admin = $this->actingAsAdminWith('inventory.view', 'inventory.move', 'inventory.adjust', 'reports.export');
        $id = $this->id('ADJ-122');

        $this->postJson("/api/v1/admin/inventory/{$id}/entries", ['quantity' => 0, 'reason' => 'NF'])->assertStatus(422);
        $this->postJson("/api/v1/admin/inventory/{$id}/entries", ['quantity' => 1.5, 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/inventory/{$id}/entries", ['quantity' => 1.5, 'reason' => 'Compra de fornecedor — NF 4521'])
            ->assertCreated()->assertJsonPath('data.movement.type', 'in')->assertJsonPath('data.movement.actor.id', $admin->id)
            ->assertJsonPath('data.inventory.on_hand', 9.5);
        $this->postJson('/api/v1/admin/inventory/'.$this->id('FIT-VHB-12').'/entries', ['quantity' => 1.5, 'reason' => 'NF 1'])->assertStatus(422);

        app(InventoryService::class)->reserve(new StockReservation('order', 77, [new StockLine($id, Quantity::ofUnits(4))]));
        $this->postJson("/api/v1/admin/inventory/{$id}/adjustments", ['new_on_hand' => 3, 'reason' => 'contagem'])
            ->assertStatus(422)->assertJsonPath('errors.new_on_hand.0', 'Existem 4,000 reservados em pedidos pendentes.');
        $this->postJson("/api/v1/admin/inventory/{$id}/adjustments", ['new_on_hand' => 9.5, 'reason' => 'contagem'])->assertStatus(422);
        $this->postJson("/api/v1/admin/inventory/{$id}/adjustments", ['new_on_hand' => 7, 'reason' => 'contagem', 'expected_on_hand' => 8])
            ->assertStatus(409)->assertJsonPath('code', 'stale_resource');
        $this->postJson("/api/v1/admin/inventory/{$id}/adjustments", ['new_on_hand' => 7, 'reason' => 'perda por emenda', 'expected_on_hand' => 9.5])
            ->assertCreated()->assertJsonPath('data.movement.on_hand_delta', -2.5)->assertJsonPath('data.inventory.reserved', 4);

        $this->patchJson("/api/v1/admin/inventory/{$id}", ['low_stock_threshold' => 2])->assertOk()->assertJsonPath('data.low_stock_threshold_override', 2);
        $this->patchJson("/api/v1/admin/inventory/{$id}", ['low_stock_threshold' => null])->assertOk()->assertJsonPath('data.low_stock_threshold', 10);

        $moves = $this->getJson("/api/v1/admin/inventory/{$id}/movements")->assertOk()->json('data');
        self::assertSame(['adjust', 'reserve', 'in', 'in'], array_column($moves, 'type'));
        self::assertSame('Pedido #77', $moves[1]['reference']['label']);
        $this->getJson("/api/v1/admin/inventory/movements?type=in&variant_id={$id}")->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/admin/inventory/movements?order_id=77')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/inventory/movements?type=bogus')->assertStatus(422);
        $csv = $this->get("/api/v1/admin/inventory/{$id}/movements?format=csv")->assertOk();
        self::assertStringContainsString('ADJ-122', $csv->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.adjusted']);
    }
}
