<?php

declare(strict_types=1);

namespace App\Modules\Cart\Actions;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartItemGuard;
use App\Modules\Catalog\Exceptions\InvalidSaleQuantity;
use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\DTOs\ShippingCustomer;
use App\Modules\Shipping\DTOs\ShippingQuoteData;
use Illuminate\Validation\ValidationException;

/**
 * POST /shipping/quote (API.md §3.A): estimate for the product page. Not persisted
 * (quote_id null); subtotal = lines resolved by PriceResolver; coupon ignored.
 */
final class EstimateProductShipping
{
    public function __construct(
        private readonly CartItemGuard $guard,
        private readonly CartService $carts,
        private readonly CustomerDirectory $customers,
        private readonly ShippingRequestFactory $requests,
        private readonly ShippingQuoteService $quotes,
    ) {}

    /** @param list<array<string, mixed>> $items */
    public function execute(?int $customerId, string $postalCode, array $items): ShippingQuoteData
    {
        $configs = [];
        foreach (array_values($items) as $i => $input) {
            $prefix = "items.{$i}.";
            try {
                $variant = $this->guard->activeVariant((int) $input['variant_id']);
                $item = new CartItem;
                $item->variant_id = $variant->id;
                $this->guard->fill($item, $variant, $input);
                $this->guard->resolve($variant, $item);
            } catch (InvalidSaleQuantity $e) {
                throw $e->withField($prefix.$e->field);
            } catch (ValidationException $e) {
                $errors = [];
                foreach ($e->errors() as $field => $messages) {
                    $errors[$prefix.$field] = $messages;
                }

                throw ValidationException::withMessages($errors);
            }
            $configs[] = ['variant_id' => $variant->id, 'quantity' => $item->quantity, 'width_mm' => $item->width_mm,
                'height_mm' => $item->height_mm, 'pieces' => $item->pieces];
        }

        ['lines' => $lines, 'subtotal' => $subtotal] = $this->carts->shippingLinesForItems($configs, $customerId);
        $customer = $customerId !== null ? $this->customers->find($customerId) : null;
        $request = $this->requests->fromLines(
            $lines,
            $postalCode,
            $subtotal,
            false,
            $customer !== null ? new ShippingCustomer($customer->id, $customer->type->value, $customer->companyId) : null,
        );

        return $this->quotes->estimate($request);
    }
}
