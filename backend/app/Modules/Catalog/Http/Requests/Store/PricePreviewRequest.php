<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation only; sale-unit rules (min/max/step, widths, fixed width,
 * prohibited-by-unit) are enforced by the controller with the variant's data.
 */
final class PricePreviewRequest extends FormRequest
{
    use ProhibitsClientPricing;

    public const string QUANTITY_REGEX = '/^\d{1,6}(\.\d{1,3})?$/';

    public const string DIMENSION_REGEX = '/^\d{1,3}(\.\d{1,2})?$/';

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['quantity', 'width_m', 'height_m'] as $key) {
            $value = $this->input($key);
            if (is_int($value) || is_float($value)) {
                $data[$key] = $this->numberToString($value);
            }
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->prohibitedFields(),
            'variant_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['nullable', 'string', 'regex:'.self::QUANTITY_REGEX],
            'width_m' => ['nullable', 'string', 'regex:'.self::DIMENSION_REGEX],
            'height_m' => ['nullable', 'string', 'regex:'.self::DIMENSION_REGEX],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'O campo :attribute não é permitido.',
            'quantity.regex' => 'Informe uma quantidade válida com até 3 casas decimais.',
            'width_m.regex' => 'Informe a largura em metros com até 2 casas decimais.',
            'height_m.regex' => 'Informe a altura em metros com até 2 casas decimais.',
        ];
    }

    private function numberToString(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        $s = var_export($value, true);

        return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
    }
}
