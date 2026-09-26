<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Store;

use App\Modules\Catalog\Exceptions\CatalogNotFound;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\StorefrontPresenter;
use App\Modules\Catalog\Support\CategoryTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class CategoryController
{
    public const string TREE_CACHE_KEY = 'catalog:categories:tree';

    public function __construct(private readonly StorefrontPresenter $presenter) {}

    public function index(): JsonResponse
    {
        $tree = Cache::remember(self::TREE_CACHE_KEY, now()->addHours(6), fn () => $this->presenter->categoryTree());

        return new JsonResponse(['data' => $tree]);
    }

    public function show(string $slug): JsonResponse
    {
        $category = Category::query()->where('slug', $slug)->first();
        if ($category === null || ! app(CategoryTree::class)->isVisible($category->id)) {
            throw CatalogNotFound::category();
        }

        return new JsonResponse(['data' => $this->presenter->categoryDetail($category)]);
    }
}
