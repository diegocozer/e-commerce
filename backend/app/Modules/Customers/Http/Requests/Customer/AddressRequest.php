<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Customer;

use App\Modules\Customers\Http\Requests\Concerns\ProhibitsDangerousFields;
use App\Modules\Customers\Support\InputNormalizer;
use App\Shared\Domain\PostalCode as PostalCodeValue;
use App\Shared\Support\PlainText;
use App\Shared\Validation\Rules\PostalCode;
use Illuminate\Foundation\Http\FormRequest;

/** POST /me/addresses and PATCH /me/addresses/{uuid} (API.md §3.D). city/state/IBGE are ignored (derived from the CEP). */
final class AddressRequest extends FormRequest
{
    use ProhibitsDangerousFields;

    private const array TEXT_FIELDS = ['street', 'number', 'complement', 'district', 'reference', 'recipient_name', 'label'];

    protected function prepareForValidation(): void
    {
        $data = [];
        if ($this->has('phone')) {
            $data['phone'] = InputNormalizer::digits($this->input('phone'));
        }
        foreach (self::TEXT_FIELDS as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $clean = PlainText::clean($value);
                $data[$field] = $clean === '' ? null : $clean;
            }
        }
        $this->merge($data);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $req = $create ? 'required' : 'sometimes';

        return [
            ...$this->prohibitedRules(),
            'postal_code' => [$req, 'string', new PostalCode],
            'street' => [$req, 'string', 'max:200'],
            'number' => [$req, 'string', 'max:20'],
            'complement' => ['sometimes', 'nullable', 'string', 'max:100'],
            'district' => [$req, 'string', 'max:100'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:200'],
            'recipient_name' => [$req, 'string', 'min:3', 'max:150'],
            'phone' => [$req, 'string', 'regex:/^\d{10,11}$/'],
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> validated data with the CEP normalized to 8 digits */
    public function addressData(): array
    {
        $data = $this->validated();
        if (isset($data['postal_code'])) {
            $data['postal_code'] = PostalCodeValue::normalize((string) $data['postal_code']);
        }
        if (isset($data['number'])) {
            $data['number'] = mb_strtoupper((string) $data['number']) === 'S/N' ? 'S/N' : $data['number'];
        }

        return $data;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...$this->prohibitedMessages(), 'phone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).'];
    }
}
