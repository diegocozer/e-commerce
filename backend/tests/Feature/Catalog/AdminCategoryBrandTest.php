<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Events\CategoryTreeChanged;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

final class AdminCategoryBrandTest extends CatalogTestCase
{
    public function test_permissions(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
        $this->actingAsAdminWith('dashboard.view');
        $this->getJson('/api/v1/admin/categories')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/v1/admin/brands')->assertForbidden();
        $this->actingAsAdminWith('products.view');
        $this->getJson('/api/v1/admin/categories')->assertOk();
        $this->postJson('/api/v1/admin/categories', ['name' => 'Nova'])->assertForbidden();
        $this->postJson('/api/v1/admin/brands', ['name' => 'Nova'])->assertForbidden();
    }

    public function test_category_crud_tree_depth_and_sanitization(): void
    {
        Event::fake([CategoryTreeChanged::class]);
        $this->actingAsAdminWith('products.view', 'products.manage');

        $root = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Mídias Especiais', 'description_html' => '<p>Ok</p><script>alert(1)</script><img src=x onerror=alert(1)>',
        ])->assertCreated()->assertJsonPath('data.slug', 'midias-especiais')->assertJsonPath('data.depth', 1);
        self::assertStringNotContainsString('script', (string) $root->json('data.description_html'));
        self::assertStringNotContainsString('onerror', (string) $root->json('data.description_html'));

        $child = $this->postJson('/api/v1/admin/categories', ['name' => 'Filha', 'parent_id' => $root->json('data.id')])->assertCreated();
        $grand = $this->postJson('/api/v1/admin/categories', ['name' => 'Neta', 'parent_id' => $child->json('data.id')])->assertCreated()->assertJsonPath('data.depth', 3);
        $this->postJson('/api/v1/admin/categories', ['name' => 'Bisneta', 'parent_id' => $grand->json('data.id')])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->patchJson('/api/v1/admin/categories/'.$root->json('data.id'), ['parent_id' => $grand->json('data.id')])->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->postJson('/api/v1/admin/categories', ['name' => 'Xx', 'slug' => 'checkout'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/categories', ['name' => 'Xx', 'slug' => 'Invalid Slug'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->postJson('/api/v1/admin/categories', ['name' => 'Xx', 'products_count' => 3])->assertStatus(422)->assertJsonValidationErrors('products_count');

        $tree = $this->getJson('/api/v1/admin/categories')->assertOk()->json('data');
        self::assertSame('Filha', $tree[0]['children'][0]['name']);

        $this->patchJson('/api/v1/admin/categories/'.$root->json('data.id'), ['name' => 'Mídias', 'expected_updated_at' => '2000-01-01T00:00:00Z'])
            ->assertStatus(409)->assertJsonPath('code', 'stale_resource');
        $this->patchJson('/api/v1/admin/categories/'.$root->json('data.id'), ['meta_title' => 'Meta'])->assertOk()->assertJsonPath('data.meta_title', 'Meta');

        Event::assertDispatched(CategoryTreeChanged::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.created']);
    }

    public function test_category_delete_blockers_and_reorder(): void
    {
        $this->actingAsAdminWith('products.view', 'products.manage');
        $cat = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $cat->id]);
        $product = Product::factory()->create(['primary_category_id' => $child->id]);

        $this->deleteJson("/api/v1/admin/categories/{$cat->id}")->assertStatus(409)->assertJsonPath('code', 'resource_in_use')
            ->assertJsonPath('blockers.0.type', 'category');
        $this->patchJson("/api/v1/admin/categories/{$child->id}", ['is_active' => false])->assertStatus(409)
            ->assertJsonPath('blockers.0', ['type' => 'product', 'id' => $product->id, 'label' => $product->name]);

        $a = Category::factory()->create(['parent_id' => $cat->id]);
        $this->postJson('/api/v1/admin/categories/reorder', ['parent_id' => $cat->id, 'ids' => [$a->id]])->assertStatus(422);
        $this->postJson('/api/v1/admin/categories/reorder', ['parent_id' => $cat->id, 'ids' => [$a->id, $child->id]])->assertNoContent();
        self::assertSame(1, $child->fresh()->position);

        $product->update(['is_active' => false]);
        $this->deleteJson("/api/v1/admin/categories/{$a->id}")->assertNoContent();
        $this->assertSoftDeleted('categories', ['id' => $a->id]);
    }

    public function test_category_image_upload_validation(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWith('products.view', 'products.manage');
        $cat = Category::factory()->create();
        $this->postJson("/api/v1/admin/categories/{$cat->id}/image", ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $res = $this->postJson("/api/v1/admin/categories/{$cat->id}/image", ['file' => UploadedFile::fake()->image('c.png', 400, 400)])->assertOk();
        self::assertNotNull($res->json('data.image_url'));
        Storage::disk('public')->assertExists($cat->fresh()->image_path);
        $this->deleteJson("/api/v1/admin/categories/{$cat->id}/image")->assertNoContent();
        self::assertNull($cat->fresh()->image_path);
    }

    public function test_brand_crud(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWith('products.view', 'products.manage');
        $brand = $this->postJson('/api/v1/admin/brands', ['name' => 'Marca Boa'])->assertCreated()->assertJsonPath('data.slug', 'marca-boa');
        $this->postJson('/api/v1/admin/brands', ['name' => 'Marca Boa'])->assertStatus(422)->assertJsonValidationErrors('name');
        $id = $brand->json('data.id');
        $this->patchJson("/api/v1/admin/brands/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/admin/brands?q=boa&sort=-name')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/brands?sort=evil')->assertStatus(422);
        $this->postJson("/api/v1/admin/brands/{$id}/logo", ['file' => UploadedFile::fake()->image('l.webp', 300, 300)])->assertOk();
        $this->deleteJson("/api/v1/admin/brands/{$id}")->assertNoContent();
        $this->assertSoftDeleted('brands', ['id' => $id]);
        self::assertSame(1, Brand::withTrashed()->where('id', $id)->count());
    }
}
