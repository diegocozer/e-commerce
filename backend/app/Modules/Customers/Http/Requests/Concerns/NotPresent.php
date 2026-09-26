<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Concerns;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * Like `prohibited`, but also fails when the key is present with null/empty
 * value (API.md §1.7: "se presentes (mesmo null) → 422").
 */
final class NotPresent implements DataAwareRule, ValidationRule
{
    public bool $implicit = true;

    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $field) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Arr::has($this->data, $attribute)) {
            $fail("O campo {$this->field} não é permitido.");
        }
    }
}
