<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Models\Coupon;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\Promotion;
use Tests\Feature\Catalog\CatalogTestCase;

final class AdminPricingTest extends CatalogTestCase
{
    public function test_price_tiers_permissions_and_non_increasing_rule(): void
    {
        $variant = ProductVariant::factory()->create(['price_cents' => 1590]);
        $list = PriceList::factory()->create(['code' => 'wholesale', 'is_default' => false]);
        $url = "/api/v1/admin/variants/{$variant->id}/price-tiers";
        $body = ['price_list_id' => null, 'tiers' => [['min_quantity' => 10, 'price_cents' => 1490], ['min_quantity' => 50, 'price_cents' => 1390]]];

        $this->actingAsAdminWith('products.view', 'pricing.manage');
        $this->putJson($url, $body)->assertForbidden();
        $this->putJson($url, [...$body, 'price_list_id' => $list->id])->assertOk()->assertJsonPath('data.price_lists.0.tiers.1.price_cents', 1390);

        $this->actingAsAdminWith('products.view', 'prices.manage');
        $this->putJson($url, [...$body, 'price_list_id' => $list->id])->assertForbidden();
        $this->putJson($url, ['price_list_id' => null, 'tiers' => [['min_quantity' => 10, 'price_cents' => 1490], ['min_quantity' => 50, 'price_cents' => 1500]]])
            ->assertStatus(422)->assertJsonValidationErrors('tiers.1.price_cents');
        $this->putJson($url, ['price_list_id' => null, 'tiers' => [['min_quantity' => 10, 'price_cents' => 1], ['min_quantity' => 10, 'price_cents' => 1]]])->assertStatus(422);
        $this->putJson($url, $body)->assertOk()->assertJsonPath('data.base.0.min_quantity', 10)->assertJsonCount(2, 'data.base');
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.base')->assertJsonCount(1, 'data.price_lists');
        $this->putJson($url, ['price_list_id' => null, 'tiers' => []])->assertOk()->assertJsonCount(0, 'data.base');
        $this->assertDatabaseHas('audit_logs', ['action' => 'price_tiers.replaced']);

        $this->actingAsAdminWith('dashboard.view');
        $this->getJson($url)->assertForbidden();
    }

    public function test_price_lists_crud_default_switch_and_in_use(): void
    {
        $retail = PriceList::factory()->create(['code' => 'retail', 'is_default' => true]);
        $this->actingAsAdminWith('products.view', 'pricing.manage');
        $id = $this->postJson('/api/v1/admin/price-lists', ['code' => 'vip_x', 'name' => 'VIP', 'kind' => 'custom', 'discount_bp' => 500])
            ->assertCreated()->assertJsonPath('data.discount_bp', 500)->json('data.id');
        $this->postJson('/api/v1/admin/price-lists', ['code' => 'VIP X', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/price-lists', ['code' => 'vip_y', 'name' => 'x', 'discount_bp' => 10000])->assertStatus(422)->assertJsonValidationErrors('discount_bp');
        $this->patchJson("/api/v1/admin/price-lists/{$id}", ['code' => 'other'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->patchJson("/api/v1/admin/price-lists/{$id}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        self::assertFalse($retail->fresh()->is_default);
        $this->deleteJson("/api/v1/admin/price-lists/{$id}")->assertStatus(409)->assertJsonPath('code', 'resource_in_use');
        $customer = Customer::factory()->create();
        $customer->forceFill(['price_list_id' => $retail->id])->save();
        $this->deleteJson("/api/v1/admin/price-lists/{$retail->id}")->assertStatus(409)->assertJsonPath('blockers.0.type', 'customer');
        $this->getJson('/api/v1/admin/price-lists')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/admin/price-lists/{$id}/tiers")->assertOk()->assertJsonPath('meta.total', 0);

        $this->actingAsAdminWith('products.view', 'prices.manage');
        $this->postJson('/api/v1/admin/price-lists', ['code' => 'zzz', 'name' => 'x'])->assertForbidden();
    }

    public function test_customer_prices_exclusivity_and_overlap(): void
    {
        $variant = ProductVariant::factory()->create();
        $customer = Customer::factory()->create();
        $this->actingAsAdminWith('customers.view', 'pricing.manage');

        $this->postJson('/api/v1/admin/customer-prices', ['variant_id' => $variant->id, 'price_cents' => 100])->assertStatus(422);
        $this->postJson('/api/v1/admin/customer-prices', ['customer_id' => $customer->id, 'company_id' => $customer->id, 'variant_id' => $variant->id, 'price_cents' => 100])->assertStatus(422);
        $id = $this->postJson('/api/v1/admin/customer-prices', ['customer_id' => $customer->id, 'variant_id' => $variant->id, 'price_cents' => 1200, 'starts_at' => '2026-01-01T00:00:00Z', 'ends_at' => '2026-12-31T00:00:00Z'])
            ->assertCreated()->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.variant.sku', $variant->sku)->json('data.id');
        $this->postJson('/api/v1/admin/customer-prices', ['customer_id' => $customer->id, 'variant_id' => $variant->id, 'price_cents' => 1100, 'starts_at' => '2026-06-01T00:00:00Z'])
            ->assertStatus(422)->assertJsonPath('errors.starts_at.0', 'Já existe preço vigente neste período.');
        $this->patchJson("/api/v1/admin/customer-prices/{$id}", ['price_cents' => 1000])->assertOk()->assertJsonPath('data.price_cents', 1000);
        $this->patchJson("/api/v1/admin/customer-prices/{$id}", ['ends_at' => '2025-01-01T00:00:00Z'])->assertStatus(422);
        $this->getJson("/api/v1/admin/customer-prices?customer_id={$customer->id}&active_at=2026-03-01T00:00:00Z")->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson("/api/v1/admin/customer-prices/{$id}")->assertNoContent();

        $this->actingAsAdminWith('pricing.manage');
        $this->getJson('/api/v1/admin/customer-prices')->assertForbidden();
    }

    public function test_promotions_crud_targets_status_and_preview(): void
    {
        $cat = Category::factory()->create();
        $variant = ProductVariant::factory()->create(['price_cents' => 1590]);
        $this->actingAsAdminWith('products.view', 'promotions.manage');

        $this->postJson('/api/v1/admin/promotions', ['name' => 'P', 'discount_type' => 'percent', 'value' => 1000, 'scope' => 'targeted', 'starts_at' => now()->subDay()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('product_ids');
        self::assertSame(0, Promotion::query()->count());
        $this->postJson('/api/v1/admin/promotions', ['name' => 'P', 'discount_type' => 'percent', 'value' => 20000, 'scope' => 'all', 'starts_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('value');

        $id = $this->postJson('/api/v1/admin/promotions', [
            'name' => 'Semana', 'discount_type' => 'percent', 'value' => 1500, 'scope' => 'targeted',
            'starts_at' => now()->subDay()->toIso8601String(), 'ends_at' => now()->addWeek()->toIso8601String(), 'category_ids' => [$cat->id],
        ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.category_ids', [$cat->id])
            ->assertJsonPath('data.targets.categories.0.slug', $cat->slug)->json('data.id');

        $this->postJson("/api/v1/admin/promotions/{$id}/preview", ['variant_ids' => [$variant->id]])->assertOk()
            ->assertJsonPath('data.0', ['variant_id' => $variant->id, 'sku' => $variant->sku, 'base_price_cents' => 1590, 'promo_price_cents' => 1352]);
        $this->patchJson("/api/v1/admin/promotions/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/v1/admin/promotions?status=inactive')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/admin/promotions?sort=bad')->assertStatus(422);
        $this->deleteJson("/api/v1/admin/promotions/{$id}")->assertNoContent();
        $this->assertSoftDeleted('promotions', ['id' => $id]);

        $this->actingAsAdminWith('products.view', 'coupons.manage');
        $this->postJson('/api/v1/admin/promotions', ['name' => 'x'])->assertForbidden();
    }

    public function test_coupons_crud_and_permissions(): void
    {
        $this->actingAsAdminWith('coupons.manage');
        $res = $this->postJson('/api/v1/admin/coupons', ['code' => ' mega15 ', 'type' => 'percent', 'value' => 1500, 'max_discount_cents' => 2000, 'min_order_cents' => 0])
            ->assertCreated()->assertJsonPath('data.code', 'MEGA15')->assertJsonPath('data.status', 'active');
        $id = $res->json('data.id');
        $this->postJson('/api/v1/admin/coupons', ['code' => 'MEGA15', 'type' => 'fixed', 'value' => 100])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/coupons', ['code' => 'FIX', 'type' => 'fixed', 'value' => 100, 'max_discount_cents' => 10])->assertStatus(422)->assertJsonValidationErrors('max_discount_cents');
        $this->postJson('/api/v1/admin/coupons', ['code' => 'FREE1', 'type' => 'free_shipping', 'value' => 999])->assertCreated()->assertJsonPath('data.value', 0);
        $this->postJson('/api/v1/admin/coupons', ['code' => 'X1X', 'type' => 'percent', 'value' => 100, 'times_used' => 5])->assertStatus(422)->assertJsonValidationErrors('times_used');
        $this->patchJson("/api/v1/admin/coupons/{$id}", ['usage_limit' => 1])->assertOk();
        Coupon::query()->whereKey($id)->update(['times_used' => 1]);
        $this->getJson("/api/v1/admin/coupons/{$id}")->assertJsonPath('data.status', 'exhausted');
        $this->getJson('/api/v1/admin/coupons?status=exhausted')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/admin/coupons/{$id}/redemptions")->assertOk()->assertJsonPath('meta.total', 0);
        $code = $this->postJson('/api/v1/admin/coupons/generate-code')->assertOk()->json('data.code');
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
        $this->deleteJson("/api/v1/admin/coupons/{$id}")->assertNoContent();

        $this->actingAsAdminWith('promotions.manage');
        $this->getJson('/api/v1/admin/coupons')->assertOk();
        $this->actingAsAdminWith('products.view');
        $this->getJson('/api/v1/admin/coupons')->assertForbidden();
    }
}
