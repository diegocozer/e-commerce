<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Customer;

use App\Modules\Customers\Contracts\TermsVersionResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** POST /me/terms-acceptance */
final class AcceptTermsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accept_terms' => ['accepted'],
            'terms_version' => [
                'required', 'string', 'max:20',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== app(TermsVersionResolver::class)->current()) {
                        $fail('Os termos foram atualizados. Recarregue a página e aceite a versão vigente.');
                    }
                },
            ],
        ];
    }
}
