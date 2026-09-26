<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\DTOs\ProductSearchQuery;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\StorefrontPresenter;
use App\Modules\Catalog\Support\ImageUrls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductAutocompleteController
{
    public function __construct(
        private readonly ProductSearch $search,
        private readonly StorefrontPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $result = $this->search->search(new ProductSearchQuery($q, limit: 8));

        $products = Product::query()->whereIn('id', $result->productIds ?: [0])
            ->whereHas('primaryCategory', fn ($c) => $c->where('is_active', true))
            ->with(ProductController::cardRelations())->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $result->productIds, true))->values()
            ->filter(fn (Product $p) => $p->variants->isNotEmpty())->values();
        $quotes = $this->presenter->quotesAtMinimum($products, ProductController::customerId());

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $categories = Category::query()->where('is_active', true)->whereRaw('public.f_unaccent(name) ILIKE public.f_unaccent(?)', [$like])
            ->orderBy('position')->limit(4)->get();
        $brands = Brand::query()->where('is_active', true)->whereRaw('public.f_unaccent(name) ILIKE public.f_unaccent(?)', [$like])
            ->orderBy('name')->limit(3)->get();

        return new JsonResponse(['data' => [
            'products' => $products->map(function (Product $p) use ($quotes, $result) {
                $image = $p->images->first();

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'url_path' => $this->presenter->productPath($p),
                    'image_url' => $image !== null ? (ImageUrls::for($image->disk, $image->path, $image->width_px)['w300'] ?? null) : null,
                    'matched_sku' => $result->matchedSkus[$p->id] ?? null,
                    'unit_price_cents' => $quotes[$p->variants->first()->id]->unitPrice->cents(),
                    'sale_unit_abbr' => $p->sale_unit->abbreviation(),
                ];
            })->values()->all(),
            'categories' => $categories->map(fn (Category $c) => $this->presenter->categoryRef($c))->values()->all(),
            'brands' => $brands->map(fn (Brand $b) => $this->presenter->brandRef($b))->values()->all(),
        ]]);
    }
}
