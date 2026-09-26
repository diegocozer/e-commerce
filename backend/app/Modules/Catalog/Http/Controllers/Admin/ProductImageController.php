<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Events\ProductSaved;
use App\Modules\Catalog\Http\Resources\Admin\AdminCatalogPresenter as P;
use App\Modules\Catalog\Jobs\DeleteImageFiles;
use App\Modules\Catalog\Jobs\ProcessProductImage;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\RecordsAudit;
use App\Modules\Catalog\Support\StoresUploadedImage;
use App\Shared\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductImageController
{
    use RecordsAudit;

    public function store(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $data = $request->validate([
            'file' => [...StoresUploadedImage::RULES, 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)->whereNull('deleted_at')],
        ]);
        [$w, $h] = getimagesize($request->file('file')->getRealPath()) ?: [0, 0];
        if ($w * $h > 40_000_000) {
            throw ValidationException::withMessages(['file' => ['A imagem deve ter no máximo 40 megapixels.']]);
        }

        $image = DB::transaction(function () use ($product, $request, $data) {
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $count = ProductImage::query()->where('product_id', $product->id)->count();
            if ($count >= (int) config('catalog.max_images_per_product', 10)) {
                throw ValidationException::withMessages(['file' => ['Limite de 10 imagens por produto atingido.']]);
            }
            $image = ProductImage::query()->create([
                'product_id' => $product->id,
                'variant_id' => $data['variant_id'] ?? null,
                'disk' => P::disk(),
                'path' => StoresUploadedImage::store($request->file('file'), 'products/'.$product->id),
                'alt' => PlainText::clean($data['alt'] ?? null) ?: null,
                'position' => $count,
            ]);
            $this->audit('product_image.created', 'product', $product->id, [], ['image_id' => $image->id]);

            return $image;
        });
        ProcessProductImage::dispatch($image->id);

        return new JsonResponse(['data' => P::image($image->fresh(), $product->name)], 201);
    }

    public function update(Request $request, int $id, int $imageId): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $image = ProductImage::query()->where('product_id', $product->id)->findOrFail($imageId);
        $data = $request->validate([
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'variant_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product->id)->whereNull('deleted_at')],
        ]);
        $before = $image->only(['alt', 'variant_id']);
        if (array_key_exists('alt', $data)) {
            $image->alt = PlainText::clean($data['alt']) ?: null;
        }
        if (array_key_exists('variant_id', $data)) {
            $image->variant_id = $data['variant_id'];
        }
        $image->save();
        $this->audit('product_image.updated', 'product', $product->id, $before, $image->only(['alt', 'variant_id']));

        return new JsonResponse(['data' => P::image($image, $product->name)]);
    }

    public function destroy(int $id, int $imageId): Response
    {
        $product = Product::query()->findOrFail($id);
        DB::transaction(function () use ($product, $imageId): void {
            $image = ProductImage::query()->where('product_id', $product->id)->lockForUpdate()->findOrFail($imageId);
            $image->delete();
            ProductImage::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get()
                ->each(fn (ProductImage $img, int $i) => $img->position !== $i ? $img->forceFill(['position' => $i])->save() : null);
            $this->audit('product_image.deleted', 'product', $product->id, ['image_id' => $image->id], []);
            DeleteImageFiles::dispatch($image->disk, $image->path);
        });
        ProductSaved::dispatch($product->id);

        return response()->noContent();
    }

    public function reorder(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $existing = ProductImage::query()->where('product_id', $product->id)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $ids = array_map('intval', $data['ids']);
        $sorted = $ids;
        sort($sorted);
        if ($sorted !== $existing) {
            throw ValidationException::withMessages(['ids' => ['Envie todas as imagens do produto, na nova ordem.']]);
        }
        DB::transaction(function () use ($ids, $product): void {
            foreach ($ids as $position => $imageId) {
                ProductImage::query()->whereKey($imageId)->update(['position' => $position]);
            }
            $this->audit('product_image.reordered', 'product', $product->id, [], ['ids' => $ids]);
        });

        $images = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->get();

        return new JsonResponse(['data' => $images->map(fn (ProductImage $i) => P::image($i, $product->name))->values()->all()]);
    }
}
