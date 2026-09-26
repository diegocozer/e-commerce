<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Models\Product;
use Tests\Feature\Catalog\CatalogTestCase;

final class SeoTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
        config(['app.url' => 'https://loja.exemplo.com.br', 'catalog.storefront_url' => null, 'seo.shell_index_path' => '/nonexistent/index.html']);
    }

    public function test_robots(): void
    {
        $res = $this->get('/robots.txt')->assertOk();
        self::assertStringStartsWith('text/plain', (string) $res->headers->get('Content-Type'));
        $body = $res->getContent();
        self::assertStringContainsString('Disallow: /checkout', $body);
        self::assertStringContainsString('Disallow: /api/', $body);
        self::assertStringContainsString('Sitemap: https://loja.exemplo.com.br/sitemap.xml', $body);
    }

    public function test_sitemap_lists_active_pages_and_is_invalidated_by_events(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        self::assertStringContainsString('<loc>https://loja.exemplo.com.br/vinis/vinil-adesivo-branco-122m</loc>', $xml);
        self::assertStringContainsString('<loc>https://loja.exemplo.com.br/vinil-adesivo</loc>', $xml);
        self::assertStringContainsString('<lastmod>', $xml);

        $product = Product::query()->where('slug', 'espatula-feltro')->firstOrFail();
        $product->update(['is_active' => false]);
        self::assertStringContainsString('espatula-feltro', $this->get('/sitemap.xml')->getContent()); // cached
        ProductSaved::dispatch($product->id);
        self::assertStringNotContainsString('espatula-feltro', $this->get('/sitemap.xml')->getContent());
    }

    public function test_product_shell_meta_and_json_ld(): void
    {
        $html = $this->get('/vinis/vinil-adesivo-branco-122m')->assertOk()->getContent();
        self::assertStringContainsString('<title>Vinil Adesivo Branco 1,22 m | CV Suprimentos</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="https://loja.exemplo.com.br/vinis/vinil-adesivo-branco-122m">', $html);
        self::assertStringContainsString('"@type":"Product"', $html);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
        self::assertStringContainsString('"price":"15.90"', $html);
        self::assertStringContainsString('<div id="root"></div>', $html);
    }

    public function test_redirect_to_canonical_category_and_404_noindex(): void
    {
        $this->get('/adesivos/vinil-adesivo-branco-122m')->assertStatus(301)
            ->assertHeader('Location', 'https://loja.exemplo.com.br/vinis/vinil-adesivo-branco-122m');
        $res = $this->get('/vinis/nao-existe')->assertNotFound();
        self::assertStringContainsString('noindex', $res->getContent());
        $this->get('/categoria-fantasma')->assertNotFound();
        $this->get('/vinis')->assertOk();
    }

    public function test_shell_uses_configured_index_and_escapes_meta(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'idx');
        file_put_contents($file, '<!doctype html><html><head><title>SPA</title><!--seo:head--></head><body><div id="app"></div></body></html>');
        config(['seo.shell_index_path' => $file]);
        Product::query()->where('slug', 'espatula-feltro')->update(['meta_title' => '</title><script>alert(1)</script>', 'meta_description' => '"><img src=x>']);

        $html = $this->get('/ferramentas/espatula-feltro')->assertOk()->getContent();
        @unlink($file);
        self::assertStringContainsString('<div id="app"></div>', $html);
        self::assertStringNotContainsString('<title>SPA</title>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x>', $html);
        self::assertStringContainsString('&lt;/title&gt;', $html);
    }

    public function test_reserved_paths_are_not_captured(): void
    {
        $this->get('/checkout')->assertNotFound();
        self::assertStringNotContainsString('noindex', (string) $this->get('/checkout')->getContent());
        $this->getJson('/api/v1/categories')->assertOk();
    }
}
