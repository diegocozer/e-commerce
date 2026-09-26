<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

final class AdminProductTest extends CatalogTestCase
{
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create(['slug' => 'vinis']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = [], array $variant = []): array
    {
        return [
            'name' => 'Vinil Teste Branco',
            'sale_unit' => 'LINEAR_METER',
            'primary_category_id' => $this->category->id,
            'min_quantity' => 1, 'quantity_step' => 0.1, 'max_quantity' => 50, 'fixed_width_m' => 1.22,
            'description_html' => '<p>Bom</p><script>x</script>',
            'specifications' => [['label' => 'Largura', 'value' => '1,22 m']],
            'variants' => [[
                'sku' => 'vin-tst-01', 'name' => 'Brilho', 'attributes' => ['acabamento' => 'Brilho'],
                'price_cents' => 1590, 'weight_grams' => 250, 'package_width_cm' => 10, 'package_height_cm' => 10,
                'initial_stock' => '100', 'low_stock_threshold' => 20, ...$variant,
            ]],
            ...$overrides,
        ];
    }

    public function test_create_product_with_variant_initial_stock_and_activation(): void
    {
        Event::fake([ProductSaved::class]);
        $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage', 'inventory.move', 'inventory.adjust');

        $res = $this->postJson('/api/v1/admin/products', $this->payload(['is_active' => true]))->assertCreated();
        $res->assertJsonPath('data.slug', 'vinil-teste-branco')
            ->assertJsonPath('data.url_path', '/vinis/vinil-teste-branco')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.fixed_width_m', 1.22)
            ->assertJsonPath('data.quantity_step', 0.1)
            ->assertJsonPath('data.category_ids', [$this->category->id])
            ->assertJsonPath('data.variants.0.sku', 'VIN-TST-01')
            ->assertJsonPath('data.variants.0.inventory.on_hand', 100)
            ->assertJsonPath('data.variants.0.inventory.low_stock_threshold', 20)
            ->assertJsonPath('data.activation_issues', []);
        self::assertStringNotContainsString('script', (string) $res->json('data.description_html'));
        $variantId = $res->json('data.variants.0.id');
        $this->assertDatabaseHas('inventory_movements', ['variant_id' => $variantId, 'type' => 'in', 'reason' => 'Estoque inicial']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created']);
        Event::assertDispatched(ProductSaved::class);
    }

    public function test_activation_issues_block_is_active(): void
    {
        $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage', 'inventory.move', 'inventory.adjust');
        $this->postJson('/api/v1/admin/products', $this->payload(['is_active' => true], ['weight_grams' => 0, 'package_width_cm' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
        $res = $this->postJson('/api/v1/admin/products', $this->payload([], ['weight_grams' => 0]))->assertCreated()->assertJsonPath('data.is_active', false);
        self::assertNotEmpty($res->json('data.activation_issues'));
    }

    public function test_field_permissions(): void
    {
        $this->actingAsAdminWith('products.view', 'products.manage');
        $this->postJson('/api/v1/admin/products', $this->payload())->assertForbidden()->assertJsonPath('code', 'forbidden');
        self::assertSame(0, Product::query()->count());

        $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage');
        $this->postJson('/api/v1/admin/products', $this->payload())->assertForbidden(); // initial_stock without inventory.move

        $admin = $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage', 'inventory.move', 'inventory.adjust');
        $id = $this->postJson('/api/v1/admin/products', $this->payload())->assertCreated()->json('data.id');
        $variant = ProductVariant::query()->where('product_id', $id)->firstOrFail();

        $this->actingAsAdminWith('products.view', 'products.manage');
        // Same price (unchanged) is allowed without prices.manage; a different price is not.
        $this->patchJson("/api/v1/admin/products/{$id}", ['variants' => [['id' => $variant->id, 'sku' => $variant->sku, 'name' => 'Novo nome', 'price_cents' => 1590]]])->assertOk();
        $this->patchJson("/api/v1/admin/products/{$id}", ['variants' => [['id' => $variant->id, 'sku' => $variant->sku, 'name' => 'X', 'price_cents' => 1000]]])->assertForbidden();
        $this->patchJson("/api/v1/admin/products/{$id}", ['variants' => [['id' => $variant->id, 'sku' => $variant->sku, 'name' => 'X', 'low_stock_threshold' => 1]]])->assertForbidden();
        self::assertSame(1590, $variant->fresh()->price_cents);
        self::assertSame('Novo nome', $variant->fresh()->name);
        // cost hidden without prices.manage / reports.view
        $this->getJson("/api/v1/admin/products/{$id}")->assertOk()->assertJsonPath('data.variants.0.cost_cents', null);
        unset($admin);
    }

    public function test_validation_and_prohibited_fields(): void
    {
        $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage', 'inventory.move', 'inventory.adjust');
        $this->postJson('/api/v1/admin/products', $this->payload(['on_hand' => 5]))->assertStatus(422)->assertJsonValidationErrors('on_hand');
        $this->postJson('/api/v1/admin/products', $this->payload([], ['reserved' => 1]))->assertStatus(422)->assertJsonValidationErrors('variants.0.reserved');
        $this->postJson('/api/v1/admin/products', $this->payload(['min_quantity' => 0.05]))->assertStatus(422)->assertJsonValidationErrors('min_quantity');
        $this->postJson('/api/v1/admin/products', $this->payload(['sale_unit' => 'UNIT', 'quantity_step' => 1, 'min_quantity' => 1, 'max_quantity' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('fixed_width_m');
        $this->postJson('/api/v1/admin/products', $this->payload([], ['sku' => 'bad sku!']))->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
        $this->postJson('/api/v1/admin/products', $this->payload([], ['promo_price_cents' => 2000]))->assertStatus(422)->assertJsonValidationErrors('variants.0.promo_price_cents');
        $this->postJson('/api/v1/admin/products', $this->payload(['slug' => 'admin']))->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/products', array_merge($this->payload(), ['variants' => []]))->assertStatus(422)->assertJsonValidationErrors('variants');

        $this->postJson('/api/v1/admin/products', $this->payload())->assertCreated();
        // RN-CAT-013: SKU of a (deleted) variant cannot be reused.
        ProductVariant::query()->where('sku', 'VIN-TST-01')->first()->delete();
        $this->postJson('/api/v1/admin/products', $this->payload(['name' => 'Outro nome']))->assertStatus(422)->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_update_upsert_variants_sale_unit_lock_and_stale(): void
    {
        $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage', 'inventory.move', 'inventory.adjust');
        $p = $this->postJson('/api/v1/admin/products', $this->payload())->assertCreated()->json('data');
        $variantId = $p['variants'][0]['id'];

        $res = $this->patchJson("/api/v1/admin/products/{$p['id']}", [
            'expected_updated_at' => $p['updated_at'],
            'variants' => [
                ['id' => $variantId, 'sku' => 'VIN-TST-01', 'name' => 'Brilho', 'price_cents' => 1690],
                ['sku' => 'VIN-TST-02', 'name' => 'Fosco', 'price_cents' => 1790, 'weight_grams' => 250, 'package_width_cm' => 10, 'package_height_cm' => 10],
            ],
        ])->assertOk();
        $res->assertJsonCount(2, 'data.variants')->assertJsonPath('data.variants.0.price_cents', 1690);
        self::assertSame(1, Inventory::query()->where('variant_id', $res->json('data.variants.1.id'))->count());

        $this->patchJson("/api/v1/admin/products/{$p['id']}", ['name' => 'x x x', 'expected_updated_at' => '2001-01-01T00:00:00Z'])->assertStatus(409)->assertJsonPath('code', 'stale_resource');
        $other = Product::factory()->create();
        $foreign = ProductVariant::factory()->create(['product_id' => $other->id]);
        $this->patchJson("/api/v1/admin/products/{$p['id']}", ['variants' => [['id' => $foreign->id, 'sku' => $foreign->sku, 'name' => 'x']]])->assertStatus(422)->assertJsonValidationErrors('variants.0.id');
        $this->patchJson("/api/v1/admin/products/{$p['id']}", ['variants' => [['id' => $variantId, 'sku' => 'VIN-TST-01', 'name' => 'x', 'initial_stock' => 5]]])->assertStatus(422);

        DB::table('orders')->exists(); // order_items presence locks the sale unit
        $order = Order::factory()->create();
        DB::table('order_items')->insert([
            'order_id' => $order->id, 'variant_id' => $variantId, 'product_id' => $p['id'], 'product_name' => 'x', 'variant_name' => 'x', 'sku' => 'VIN-TST-01',
            'sale_unit' => 'LINEAR_METER', 'quantity' => '1', 'billable_quantity' => '1', 'stock_quantity' => '1', 'base_unit_price_cents' => 1590,
            'unit_price_cents' => 1590, 'price_source' => 'base', 'subtotal_cents' => 1590, 'total_cents' => 1590, 'weight_grams' => 250,
        ]);
        $this->patchJson("/api/v1/admin/products/{$p['id']}", ['sale_unit' => 'KG', 'fixed_width_m' => null])->assertStatus(422)->assertJsonValidationErrors('sale_unit');
        $this->getJson("/api/v1/admin/products/{$p['id']}")->assertJsonPath('data.sale_unit_locked', true)->assertJsonPath('data.variants.0.has_orders', true);
    }

    public function test_listing_delete_variant_bulk_and_availability_endpoints(): void
    {
        $this->actingAsAdminWith('products.view', 'products.manage', 'prices.manage', 'inventory.move', 'inventory.adjust');
        $id = $this->postJson('/api/v1/admin/products', $this->payload(['is_active' => true]))->assertCreated()->json('data.id');
        $variantId = ProductVariant::query()->where('product_id', $id)->value('id');

        $this->getJson('/api/v1/admin/products?q=vin-tst&status=active&sort=-min_price')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.skus', ['VIN-TST-01'])->assertJsonPath('data.0.total_available', 100);
        $this->getJson('/api/v1/admin/products?sort=hack')->assertStatus(422);
        $this->getJson("/api/v1/admin/products?category_id={$this->category->id}&low_stock=1")->assertOk()->assertJsonPath('meta.total', 0);

        $this->deleteJson("/api/v1/admin/products/{$id}/variants/{$variantId}")->assertStatus(409)->assertJsonPath('code', 'resource_in_use');

        $this->getJson('/api/v1/admin/products/slug-availability?slug=vinil-teste-branco')->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.suggestion', 'vinil-teste-branco-2');
        $this->getJson("/api/v1/admin/products/slug-availability?slug=vinil-teste-branco&ignore_id={$id}")->assertJsonPath('data.available', true);
        $this->getJson('/api/v1/admin/variants/sku-availability?sku=vin-tst-01')->assertOk()
            ->assertJsonPath('data.available', false)->assertJsonPath('data.used_by.product_id', $id);
        $this->getJson('/api/v1/admin/variants?q=VIN-TST')->assertOk()->assertJsonPath('data.0.sku', 'VIN-TST-01')->assertJsonPath('data.0.sale_unit', 'LINEAR_METER');

        $bad = Product::factory()->create(['is_active' => false]);
        $res = $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$id, $bad->id, 999999], 'action' => 'activate'])->assertOk();
        self::assertSame([$id], $res->json('data.succeeded'));
        self::assertCount(2, $res->json('data.failed'));
        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$id], 'action' => 'deactivate'])->assertOk();
        self::assertFalse(Product::query()->find($id)->is_active);
        $this->postJson('/api/v1/admin/products/bulk', ['ids' => [$id], 'action' => 'set_primary_category'])->assertStatus(422);

        $this->deleteJson("/api/v1/admin/products/{$id}")->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $id]);
        $this->assertSoftDeleted('product_variants', ['id' => $variantId]);
    }

    public function test_images_upload_process_reorder_delete(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWith('products.view', 'products.manage');
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        $this->postJson("/api/v1/admin/products/{$product->id}/images", ['file' => UploadedFile::fake()->create('a.gif', 10, 'image/gif')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson("/api/v1/admin/products/{$product->id}/images", ['file' => UploadedFile::fake()->image('tiny.png', 50, 50)])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson("/api/v1/admin/products/{$product->id}/images", ['file' => UploadedFile::fake()->image('big.jpg', 900, 900)->size(6000)])
            ->assertStatus(422);

        $first = $this->postJson("/api/v1/admin/products/{$product->id}/images", ['file' => UploadedFile::fake()->image('a.png', 900, 600), 'alt' => 'Frente', 'variant_id' => $variant->id])
            ->assertCreated()->assertJsonPath('data.position', 0)->assertJsonPath('data.variant_id', $variant->id);
        // queue is sync in tests: job already produced the derivatives
        $image = ProductImage::query()->findOrFail($first->json('data.id'));
        self::assertSame(900, $image->width_px);
        Storage::disk('public')->assertExists(str_replace('.png', '-300.webp', $image->path));

        $second = $this->postJson("/api/v1/admin/products/{$product->id}/images", ['file' => UploadedFile::fake()->image('b.jpg', 400, 400)])->assertCreated();
        $this->postJson("/api/v1/admin/products/{$product->id}/images/reorder", ['ids' => [$second->json('data.id'), $image->id]])->assertOk()
            ->assertJsonPath('data.0.id', $second->json('data.id'));
        $this->patchJson("/api/v1/admin/products/{$product->id}/images/{$image->id}", ['alt' => 'Verso', 'variant_id' => null])->assertOk()->assertJsonPath('data.alt', 'Verso');
        $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($image->path);
        $other = Product::factory()->create();
        $this->deleteJson("/api/v1/admin/products/{$other->id}/images/{$second->json('data.id')}")->assertNotFound();
    }
}
