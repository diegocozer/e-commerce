<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Pricing\Models\Promotion;

final class StorefrontCatalogTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    public function test_category_tree_and_detail_with_breadcrumbs(): void
    {
        $tree = $this->getJson('/api/v1/categories')->assertOk()->json('data');
        self::assertSame('lonas', $tree[0]['slug']);
        $vinis = collect($tree)->firstWhere('slug', 'vinis');
        self::assertSame(['vinil-adesivo', 'vinil-transparente'], array_column($vinis['children'], 'slug'));

        $this->getJson('/api/v1/categories/vinil-adesivo')->assertOk()
            ->assertJsonPath('data.breadcrumbs', [
                ['name' => 'Início', 'url_path' => '/'], ['name' => 'Vinis', 'url_path' => '/vinis'], ['name' => 'Vinil Adesivo', 'url_path' => null],
            ])
            ->assertJsonPath('data.seo.json_ld.0.@type', 'BreadcrumbList')
            ->assertJsonPath('data.url_path', '/vinil-adesivo');

        $this->getJson('/api/v1/categories/nao-existe')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_brands_are_listed_by_name(): void
    {
        $names = array_column($this->getJson('/api/v1/brands')->assertOk()->json('data'), 'name');
        self::assertSame(['Coloraço', 'FerroVale', 'Imprimax', 'PapelNorte', 'VinilSul'], $names);
    }

    public function test_product_detail_matches_api_example(): void
    {
        $res = $this->getJson('/api/v1/products/vinil-adesivo-branco-122m')->assertOk();
        $res->assertJsonPath('data.url_path', '/vinis/vinil-adesivo-branco-122m')
            ->assertJsonPath('data.sale_unit', 'LINEAR_METER')
            ->assertJsonPath('data.sale_unit_abbr', 'm')
            ->assertJsonPath('data.breadcrumbs.1', ['name' => 'Vinis', 'url_path' => '/vinis'])
            ->assertJsonPath('data.variants.0.sku', 'VIN-BR-122-BR')
            ->assertJsonPath('data.variants.0.is_default', true)
            ->assertJsonPath('data.variants.0.rules.fixed_width_m', 1.22)
            ->assertJsonPath('data.variants.0.rules.quantity_step', 0.1)
            ->assertJsonPath('data.variants.0.price.unit_price_cents', 1590)
            ->assertJsonPath('data.variants.0.price.price_source', 'base')
            ->assertJsonPath('data.variants.0.price.tiers', [
                ['min_quantity' => 1, 'max_quantity' => 9.9, 'unit_price_cents' => 1590, 'price_source' => 'base'],
                ['min_quantity' => 10, 'max_quantity' => 49.9, 'unit_price_cents' => 1490, 'price_source' => 'tier'],
                ['min_quantity' => 50, 'max_quantity' => null, 'unit_price_cents' => 1390, 'price_source' => 'tier'],
            ])
            ->assertJsonPath('data.variants.0.availability.status', 'in_stock')
            ->assertJsonPath('data.variants.1.price.tiers', [])
            ->assertJsonPath('data.seo.json_ld.0.@type', 'Product')
            ->assertJsonPath('data.seo.json_ld.0.offers.0.price', '15.90')
            ->assertJsonPath('data.seo.json_ld.1.@type', 'BreadcrumbList');
        self::assertArrayNotHasKey('cost_cents', $res->json('data.variants.0'));
    }

    public function test_low_stock_shows_quantity_and_out_of_stock_hides_it(): void
    {
        $this->getJson('/api/v1/products/adesivo-jateado-122m')->assertOk()
            ->assertJsonPath('data.variants.0.availability', ['status' => 'low_stock', 'available_quantity' => 8]);
        $tinta = $this->getJson('/api/v1/products/tinta-eco-solvente-1l')->assertOk()->json('data.variants');
        self::assertSame(['status' => 'out_of_stock', 'available_quantity' => null], collect($tinta)->firstWhere('sku', 'TIN-ECO-1L-K')['availability']);
    }

    public function test_inactive_product_returns_404_with_category(): void
    {
        Product::query()->where('slug', 'espatula-feltro')->update(['is_active' => false]);
        $this->getJson('/api/v1/products/espatula-feltro')->assertNotFound()
            ->assertJsonPath('code', 'not_found')->assertJsonPath('category.slug', 'ferramentas');
        $this->getJson('/api/v1/products/nada')->assertNotFound()->assertJsonPath('category', null);
    }

    public function test_product_listing_filters_sort_facets_and_pagination(): void
    {
        $res = $this->getJson('/api/v1/products?category=vinis&per_page=2&sort=price')->assertOk();
        $res->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', 'vinil-adesivo-branco-122m')
            ->assertJsonPath('data.0.price.unit_price_cents', 1590)
            ->assertJsonPath('data.0.price.from_price_cents', 1390)
            ->assertJsonPath('data.0.key_attribute', 'Largura 1,22 m');
        self::assertContains(['slug' => 'vinilsul', 'name' => 'VinilSul', 'count' => 3], $res->json('facets.brands'));
        self::assertNotNull($res->json('facets.price_range_cents'));

        $this->getJson('/api/v1/products?brand=papelnorte&sale_unit=BOX')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'papel-sulfite-a4-75g-caixa');
        $this->getJson('/api/v1/products?featured=1')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/products?price_min_cents=30000&price_max_cents=40000')->assertOk()
            ->assertJsonPath('meta.total', 3); // bobina 350,00, tinta 329,00, ilhós caixa 380,00
        $this->getJson('/api/v1/products?on_sale=1')->assertOk()->assertJsonFragment(['slug' => 'fita-dupla-face-19mm'])
            ->assertJsonFragment(['slug' => 'vinil-transparente-100m']);
        $this->getJson('/api/v1/products?in_stock=1&category=tintas')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/products?category=inexistente')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_listing_validation(): void
    {
        $this->getJson('/api/v1/products?sort=hacker')->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/products?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/products?q=a')->assertStatus(422)->assertJsonValidationErrors('q');
        $this->getJson('/api/v1/products?category=DROP%20TABLE')->assertStatus(422);
    }

    public function test_search_is_accent_insensitive_with_sku_prefix_and_exact_match(): void
    {
        $this->getJson('/api/v1/products?q=ilhos')->assertOk()->assertJsonFragment(['slug' => 'ilhos-n0-latao'])
            ->assertJsonPath('search.q', 'ilhos');
        $this->getJson('/api/v1/products?q=espátula')->assertOk()->assertJsonPath('data.0.slug', 'espatula-feltro');
        $this->getJson('/api/v1/products?q=VIN-BR-122-BR')->assertOk()
            ->assertJsonPath('data.0.slug', 'vinil-adesivo-branco-122m')
            ->assertJsonPath('search.exact_sku_match.sku', 'VIN-BR-122-BR')
            ->assertJsonPath('search.exact_sku_match.url_path', '/vinis/vinil-adesivo-branco-122m');
        $this->getJson('/api/v1/products?q=bob')->assertOk()->assertJsonFragment(['slug' => 'bobina-papel-kraft-80g']);
        $this->getJson("/api/v1/products?q=' OR 1=1 --")->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_autocomplete(): void
    {
        $data = $this->getJson('/api/v1/products/autocomplete?q=vin')->assertOk()->json('data');
        self::assertNotEmpty($data['products']);
        self::assertLessThanOrEqual(8, count($data['products']));
        self::assertContains('vinis', array_column($data['categories'], 'slug'));
        $sku = $this->getJson('/api/v1/products/autocomplete?q=FIT-VHB')->assertOk()->json('data.products.0');
        self::assertSame('FIT-VHB-12', $sku['matched_sku']);
        $this->getJson('/api/v1/products/autocomplete')->assertStatus(422);
    }

    public function test_related_products(): void
    {
        $data = $this->getJson('/api/v1/products/fita-dupla-face-19mm/related')->assertOk()->json('data');
        self::assertSame(['fita-vhb-12mm'], array_column($data, 'slug'));
    }

    public function test_prices_are_resolved_for_logged_customer(): void
    {
        $customer = Customer::query()->where('email', 'compras@graficaexemplo.com.br')->firstOrFail();
        $this->actingAs($customer, 'customer');
        // Reseller list (15%) = 25,50 beats the company price 25,90 (lowest wins).
        $variants = $this->getJson('/api/v1/products/lona-frontlight-440g')->assertOk()->json('data.variants');
        $sm = collect($variants)->firstWhere('sku', 'LON-FL-440-SM');
        self::assertSame(2550, $sm['price']['unit_price_cents']);
        self::assertSame('price_list', $sm['price']['price_source']);
        self::assertSame('Preço Revendedor', $sm['price']['price_source_label']);
        self::assertSame(3000, $sm['price']['compare_at_cents']);

        $this->app['auth']->guard('customer')->logout();
        $visitor = collect($this->getJson('/api/v1/products/lona-frontlight-440g')->json('data.variants'))->firstWhere('sku', 'LON-FL-440-SM');
        self::assertSame(3000, $visitor['price']['unit_price_cents']);
    }

    public function test_seed_promotion_does_not_affect_white_vinyl(): void
    {
        self::assertTrue(Promotion::query()->where('name', 'Semana do Vinil')->exists());
        $this->postJson('/api/v1/products/vinil-adesivo-branco-122m/price-preview', [
            'variant_id' => ProductVariant::query()->where('sku', 'VIN-BR-122-BR')->value('id'), 'quantity' => 5,
        ])->assertOk()->assertJsonPath('data.line_total_cents', 7950);
    }
}
