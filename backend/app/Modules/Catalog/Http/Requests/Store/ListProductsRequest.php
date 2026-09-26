<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Store;

use App\Modules\Catalog\Services\ProductListing;
use App\Shared\Domain\SaleUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListProductsRequest extends FormRequest
{
    private const array LISTS = ['category', 'brand', 'sale_unit'];

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (self::LISTS as $key) {
            if (is_string($this->query($key))) {
                $data[$key] = array_values(array_filter(array_map('trim', explode(',', (string) $this->query($key))), fn ($v) => $v !== ''));
            }
        }
        foreach (['in_stock', 'featured', 'on_sale'] as $key) {
            if ($this->has($key)) {
                $data[$key] = in_array(strtolower((string) $this->query($key)), ['1', 'true'], true) ? true
                    : (in_array(strtolower((string) $this->query($key)), ['0', 'false'], true) ? false : $this->query($key));
            }
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'category' => ['sometimes', 'array', 'max:20'],
            'category.*' => ['string', 'max:140', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'brand' => ['sometimes', 'array', 'max:20'],
            'brand.*' => ['string', 'max:140', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'sale_unit' => ['sometimes', 'array'],
            'sale_unit.*' => [Rule::enum(SaleUnit::class)],
            'price_min_cents' => ['sometimes', 'integer', 'min:0'],
            'price_max_cents' => ['sometimes', 'integer', 'min:0'],
            'in_stock' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'on_sale' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(ProductListing::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
