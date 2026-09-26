<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Contracts\StorefrontSeo;
use App\Modules\Catalog\Exceptions\CatalogNotFound;
use App\Modules\Catalog\Http\Controllers\Store\ProductController;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CategoryTree;

final class CatalogStorefrontSeo implements StorefrontSeo
{
    public function __construct(private readonly StorefrontPresenter $presenter) {}

    public function product(string $productSlug): ?array
    {
        try {
            $product = ProductController::findVisible($productSlug);
        } catch (CatalogNotFound) {
            return null;
        }
        $variants = $product->variants;

        return [
            'seo' => $this->presenter->productSeo($product, $this->presenter->productBreadcrumbs($product), $variants, $this->presenter->availability($variants)),
            'primary_category_slug' => (string) $product->primaryCategory?->slug,
        ];
    }

    public function category(string $categorySlug): ?array
    {
        $category = Category::query()->where('slug', $categorySlug)->first();
        if ($category === null || ! app(CategoryTree::class)->isVisible($category->id)) {
            return null;
        }

        return $this->presenter->categoryDetail($category)['seo'];
    }

    public function sitemapEntries(): array
    {
        $tree = app(CategoryTree::class);
        $entries = [];
        foreach (Category::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get() as $c) {
            if ($tree->isVisible($c->id)) {
                $entries[] = ['path' => '/'.$c->slug, 'lastmod' => $c->updated_at?->utc()->format('Y-m-d\TH:i:s\Z')];
            }
        }
        $products = Product::query()->where('is_active', true)
            ->whereHas('variants', fn ($v) => $v->where('is_active', true))
            ->with('primaryCategory')->orderBy('id')->get();
        foreach ($products as $p) {
            if ($p->primaryCategory !== null && $tree->isVisible($p->primary_category_id)) {
                $entries[] = ['path' => '/'.$p->primaryCategory->slug.'/'.$p->slug, 'lastmod' => $p->updated_at?->utc()->format('Y-m-d\TH:i:s\Z')];
            }
        }

        return $entries;
    }
}
