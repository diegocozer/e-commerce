<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Exceptions\CatalogNotFound;
use App\Modules\Catalog\Http\Requests\Store\ListProductsRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductListing;
use App\Modules\Catalog\Services\StorefrontPresenter;
use App\Modules\Catalog\Support\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class ProductController
{
    public function __construct(
        private readonly StorefrontPresenter $presenter,
        private readonly ProductListing $listing,
    ) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        $result = $this->listing->run($request->validated());
        $paginator = $result['paginator'];
        $body = $paginator->toArray();
        $body['data'] = $this->presenter->cards($paginator->getCollection(), self::customerId());

        return new JsonResponse([
            'data' => $body['data'],
            'links' => ['first' => $body['first_page_url'], 'last' => $body['last_page_url'], 'prev' => $body['prev_page_url'], 'next' => $body['next_page_url']],
            'meta' => [
                'current_page' => $body['current_page'], 'from' => $body['from'], 'last_page' => $body['last_page'],
                'path' => $body['path'], 'per_page' => $body['per_page'], 'to' => $body['to'], 'total' => $body['total'],
                'links' => $body['links'],
            ],
            'facets' => $result['facets'],
            'search' => $result['search'],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = self::findVisible($slug);

        return new JsonResponse(['data' => $this->presenter->detail($product, self::customerId())]);
    }

    public function related(string $slug): JsonResponse
    {
        $product = self::findVisible($slug);
        $categoryIds = $product->categories->pluck('id')->push($product->primary_category_id)->unique()->values()->all();

        $related = Product::query()
            ->where('is_active', true)->whereKeyNot($product->id)
            ->whereHas('variants', fn ($v) => $v->where('is_active', true))
            ->whereHas('primaryCategory', fn ($c) => $c->where('is_active', true))
            ->where(fn (Builder $w) => $w->where('primary_category_id', $product->primary_category_id)
                ->orWhereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)))
            ->orderByRaw('CASE WHEN primary_category_id = ? THEN 0 ELSE 1 END', [$product->primary_category_id])
            ->orderByDesc('is_featured')->orderBy('id')
            ->limit(8)
            ->with(self::cardRelations())
            ->get();

        return new JsonResponse(['data' => $this->presenter->cards($related, self::customerId())]);
    }

    /** @return array<int|string, mixed> */
    public static function cardRelations(): array
    {
        return [
            'variants' => fn ($v) => $v->where('is_active', true),
            'variants.product', 'images', 'brand', 'primaryCategory', 'categories',
        ];
    }

    public static function findVisible(string $slug): Product
    {
        $product = Product::query()->where('slug', $slug)
            ->with([...self::cardRelations(), 'images'])
            ->first();

        if ($product === null) {
            throw CatalogNotFound::product(null);
        }
        if (! $product->is_active || $product->variants->isEmpty() || ! app(CategoryTree::class)->isVisible($product->primary_category_id)) {
            $category = $product->primaryCategory;
            throw CatalogNotFound::product($category !== null && $category->is_active
                ? ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'url_path' => '/'.$category->slug]
                : null);
        }

        return $product;
    }

    public static function customerId(): ?int
    {
        $id = Auth::guard('customer')->id();

        return $id !== null ? (int) $id : null;
    }
}
