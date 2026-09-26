<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests\Concerns;

/**
 * API.md §1.7 / ADR-012: fields that must never come from the storefront. Present
 * (even null) → 422 "O campo X não é permitido." and nothing is written.
 */
trait ProhibitsDangerousFields
{
    /** @var list<string> */
    protected static array $dangerousFields = [
        'price', 'price_cents', 'unit_price_cents', 'base_unit_price_cents', 'line_total_cents',
        'subtotal_cents', 'total_cents', 'discount', 'discount_cents', 'shipping_price', 'shipping_cents',
        'shipping_price_cents', 'shipping_discount_cents', 'customer_id', 'company_id', 'status',
        'payment_status', 'price_list_id', 'is_active', 'email_verified_at', 'roles', 'permissions',
        'uuid', 'id',
    ];

    /**
     * @param  list<string>  $extra
     * @return array<string, list<string>>
     */
    protected function prohibitedRules(array $extra = []): array
    {
        $rules = [];
        foreach ([...self::$dangerousFields, ...$extra] as $field) {
            $rules[$field] = [new NotPresent($field)];
        }

        return $rules;
    }

    /**
     * @param  list<string>  $extra
     * @return array<string, string>
     */
    protected function prohibitedMessages(array $extra = []): array
    {
        $messages = [];
        foreach ([...self::$dangerousFields, ...$extra] as $field) {
            $messages[$field.'.prohibited'] = "O campo {$field} não é permitido.";
        }

        return $messages;
    }
}
