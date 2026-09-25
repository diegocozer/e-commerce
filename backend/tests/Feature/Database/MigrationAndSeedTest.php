<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Enums\AdminPermission;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Pricing\Models\Promotion;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class MigrationAndSeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_seed_creates_the_reference_data_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class); // second run must not fail nor duplicate

        self::assertSame(21, Product::query()->count());
        self::assertSame(27, ProductVariant::query()->count());
        self::assertSame(27, Inventory::query()->count());
        self::assertSame(14, DB::table('categories')->count());
        self::assertSame(5, DB::table('brands')->count());
        self::assertSame(4, DB::table('shipping_methods')->count());
        self::assertSame(8, DB::table('shipping_zones')->count());
        self::assertSame(13, DB::table('shipping_rules')->count());
        self::assertSame(3, DB::table('price_lists')->count());
        self::assertSame(2, DB::table('customers')->count());
        self::assertSame(3, DB::table('coupons')->count());
        self::assertSame(count(AdminPermission::cases()), DB::table('permissions')->where('guard_name', 'admin')->count());
        self::assertTrue(DB::table('ibge_cities')->where('ibge_code', '4202404')->exists());
    }

    public function test_every_sale_unit_is_covered(): void
    {
        $units = Product::query()->distinct()->pluck('sale_unit')->map(fn (SaleUnit $u): string => $u->value)->sort()->values()->all();

        self::assertSame(['BOX', 'KG', 'LINEAR_METER', 'ROLL', 'SQUARE_METER', 'UNIT'], $units);
    }

    public function test_acceptance_product_vinil_adesivo_branco(): void
    {
        $product = Product::query()->where('slug', 'vinil-adesivo-branco-122m')->firstOrFail();
        $variant = ProductVariant::query()->where('sku', 'VIN-BR-122-BR')->firstOrFail();

        self::assertSame(SaleUnit::LinearMeter, $product->sale_unit);
        self::assertSame(1220, $product->fixed_width_mm);
        self::assertSame('vinis', DB::table('categories')->where('id', $product->primary_category_id)->value('slug'));
        self::assertSame(1590, $variant->price_cents);
        self::assertSame(7950, Money::ofCents($variant->price_cents)->multiplyByQuantity(Quantity::fromString('5'))->cents());
        self::assertSame('500.000', $variant->inventory?->on_hand->toDecimalString());

        // ADR-028: "Semana do Vinil" must not affect this product.
        $promotion = Promotion::query()->where('name', 'Semana do Vinil')->firstOrFail();
        self::assertNotContains($product->id, $promotion->targetIds('product'));
        self::assertSame([], $promotion->targetIds('category'));
        self::assertSame([], $promotion->targetIds('brand'));
    }

    public function test_reference_prices_of_the_seed(): void
    {
        self::assertSame(3000, ProductVariant::query()->where('sku', 'LON-FL-440-SM')->value('price_cents'));
        self::assertSame(50, ProductVariant::query()->where('sku', 'ILH-0-LAT')->value('price_cents'));
        self::assertSame(35000, ProductVariant::query()->where('sku', 'BOB-SUL90-914')->value('price_cents'));
        self::assertTrue(DB::table('shipping_rules')->where('name', 'Grátis acima de R$ 500')->where('min_subtotal_cents', 50000)->where('price_type', 'free')->exists());
        self::assertTrue(DB::table('shipping_methods')->where('code', 'pickup-store')->where('pickup_city', 'Blumenau')->exists());
    }

    public function test_admin_user_is_super_admin_and_roles_follow_adr_027(): void
    {
        $admin = AdminUser::query()->where('email', 'admin@example.com')->firstOrFail();

        self::assertTrue(Hash::check('password', $admin->password));
        self::assertTrue($admin->isSuperAdmin());
        self::assertTrue($admin->can(AdminPermission::AdminUsersManage->value));

        $manager = AdminUser::factory()->withRole(AdminRole::Manager)->create();
        self::assertTrue($manager->can(AdminPermission::SettingsManage->value));
        self::assertFalse($manager->can(AdminPermission::AdminUsersManage->value));

        $seller = AdminUser::factory()->withRole(AdminRole::Seller)->create();
        self::assertTrue($seller->can(AdminPermission::CouponsManage->value));
        self::assertFalse($seller->can(AdminPermission::OrdersCancelPaid->value));
        self::assertSame('admin_user', DB::table('model_has_roles')->where('model_id', $seller->id)->value('model_type'));
    }

    public function test_full_text_search_vector_is_populated(): void
    {
        $names = DB::table('products')
            ->whereRaw("search_vector @@ websearch_to_tsquery('public.pt_unaccent', ?)", ['ilhos'])
            ->pluck('name');

        self::assertContains('Ilhós nº 0 latão', $names->all());
    }
}
