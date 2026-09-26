<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Admin;

use App\Modules\Catalog\Support\SlugRules;
use App\Shared\Domain\SaleUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Body of POST/PATCH /admin/products (API.md §3.G.5). Cross-field unit rules live in SaveProduct. */
final class ProductRequest extends FormRequest
{
    public const string DECIMAL3 = '/^\d{1,9}(\.\d{1,3})?$/';

    public const string DECIMAL1 = '/^\d{1,6}(\.\d)?$/';

    private const array DECIMAL_FIELDS = ['min_quantity', 'max_quantity', 'quantity_step', 'min_billable_area_m2', 'fixed_width_m',
        'min_width_m', 'max_width_m', 'min_height_m', 'max_height_m'];

    private const array VARIANT_DECIMALS = ['package_length_cm', 'package_width_cm', 'package_height_cm', 'roll_length_m',
        'fixed_width_m', 'initial_stock', 'low_stock_threshold'];

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (self::DECIMAL_FIELDS as $key) {
            if (is_int($this->input($key)) || is_float($this->input($key))) {
                $data[$key] = self::str($this->input($key));
            }
        }
        if (is_array($this->input('variants'))) {
            $variants = $this->input('variants');
            foreach ($variants as $i => $v) {
                if (! is_array($v)) {
                    continue;
                }
                foreach (self::VARIANT_DECIMALS as $key) {
                    if (isset($v[$key]) && (is_int($v[$key]) || is_float($v[$key]))) {
                        $variants[$i][$key] = self::str($v[$key]);
                    }
                }
                if (isset($v['sku']) && is_string($v['sku'])) {
                    $variants[$i]['sku'] = mb_strtoupper(trim($v['sku']));
                }
            }
            $data['variants'] = $variants;
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('id') !== null ? (int) $this->route('id') : null;
        $req = $id === null ? 'required' : 'sometimes';
        $dec = ['nullable', 'string', 'regex:'.self::DECIMAL3];
        $variantIds = collect($this->input('variants', []))->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();

        return [
            'name' => [$req, 'string', 'min:3', 'max:150'],
            'slug' => ['sometimes', 'nullable', ...SlugRules::rules('products', 220, $id)],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'specifications' => ['sometimes', 'nullable', 'array', 'max:30'],
            'specifications.*.label' => ['required', 'string', 'min:1', 'max:60'],
            'specifications.*.value' => ['required', 'string', 'min:1', 'max:200'],
            'sale_unit' => [$req, Rule::enum(SaleUnit::class)],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->whereNull('deleted_at')],
            'primary_category_id' => [$req, 'integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'category_ids' => ['sometimes', 'array', 'max:20'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            'min_quantity' => [$req, 'string', 'regex:'.self::DECIMAL3],
            'max_quantity' => ['sometimes', ...$dec],
            'quantity_step' => [$req, 'string', 'regex:'.self::DECIMAL3],
            'min_billable_area_m2' => ['sometimes', ...$dec],
            'fixed_width_m' => ['sometimes', ...$dec],
            'min_width_m' => ['sometimes', ...$dec],
            'max_width_m' => ['sometimes', ...$dec],
            'min_height_m' => ['sometimes', ...$dec],
            'max_height_m' => ['sometimes', ...$dec],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:320'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'pickup_only' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['sometimes', 'date'],
            'variants' => [$id === null ? 'required' : 'sometimes', 'array', $id === null ? 'min:1' : 'min:0', 'max:50'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.sku' => ['required', 'string', 'regex:/^[A-Z0-9-]{3,40}$/', 'distinct',
                // RN-CAT-013: SKUs of deleted variants cannot be reused (unique including trashed).
                function (string $attribute, mixed $value, \Closure $fail) use ($variantIds): void {
                    $exists = \Illuminate\Support\Facades\DB::table('product_variants')->where('sku', $value)
                        ->when($variantIds !== [], fn ($q) => $q->whereNotIn('id', $variantIds))->exists();
                    if ($exists) {
                        $fail('Este SKU já está em uso.');
                    }
                }],
            'variants.*.gtin' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{8,14}$/'],
            'variants.*.name' => ['required', 'string', 'min:1', 'max:200'],
            'variants.*.attributes' => ['sometimes', 'nullable', 'array', 'max:10'],
            'variants.*.attributes.*' => ['string', 'min:1', 'max:100'],
            'variants.*.price_cents' => ['sometimes', 'integer', 'min:1'],
            'variants.*.promo_price_cents' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variants.*.promo_starts_at' => ['sometimes', 'nullable', 'date'],
            'variants.*.promo_ends_at' => ['sometimes', 'nullable', 'date'],
            'variants.*.cost_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'variants.*.weight_grams' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'variants.*.package_length_cm' => ['sometimes', 'nullable', 'string', 'regex:'.self::DECIMAL1],
            'variants.*.package_width_cm' => ['sometimes', 'nullable', 'string', 'regex:'.self::DECIMAL1],
            'variants.*.package_height_cm' => ['sometimes', 'nullable', 'string', 'regex:'.self::DECIMAL1],
            'variants.*.roll_length_m' => ['sometimes', ...$dec],
            'variants.*.units_per_box' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variants.*.units_per_package' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variants.*.fixed_width_m' => ['sometimes', ...$dec],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.position' => ['sometimes', 'integer', 'min:0'],
            'variants.*.initial_stock' => ['sometimes', ...$dec],
            'variants.*.low_stock_threshold' => ['sometimes', ...$dec],
            'variants.*.on_hand' => ['prohibited'],
            'variants.*.reserved' => ['prohibited'],
            'variants.*.available' => ['prohibited'],
            'on_hand' => ['prohibited'],
            'reserved' => ['prohibited'],
            'available' => ['prohibited'],
            'activation_issues' => ['prohibited'],
            'sale_unit_locked' => ['prohibited'],
            'url_path' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'O campo :attribute não é permitido.',
            'variants.*.sku.regex' => 'SKU inválido: use 3 a 40 caracteres A-Z, 0-9 e hífen.',
            'variants.*.sku.distinct' => 'SKU repetido.',
        ];
    }

    private static function str(int|float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        $s = var_export($v, true);

        return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
    }
}
