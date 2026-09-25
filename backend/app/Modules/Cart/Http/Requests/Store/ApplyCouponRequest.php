<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

final class ApplyCouponRequest extends FormRequest
{
    use ProhibitsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [...$this->prohibitedFields(), 'code' => ['required', 'string', 'min:3', 'max:40']];
    }
}
