<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Cart\Contracts\CartPresenter;
use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\DTOs\CartLine;
use App\Modules\Cart\DTOs\CartSnapshot;
use App\Modules\Cart\Enums\CartLineStatus;
use App\Modules\Checkout\DTOs\CheckoutData;
use App\Modules\Checkout\Exceptions\CheckoutFailed;
use App\Modules\Customers\Contracts\CustomerDirectory;
use App\Modules\Customers\DTOs\AddressData;
use App\Modules\Customers\DTOs\CustomerData;
use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Orders\Contracts\OrderPlacement;
use App\Modules\Orders\Exceptions\TooManyPendingOrders;
use App\Modules\Pricing\Contracts\CouponService;
use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Modules\Pricing\Exceptions\CouponInvalid;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Contracts\ShippingRequestFactory;
use App\Modules\Shipping\DTOs\ShippingCustomer;
use App\Modules\Shipping\DTOs\ShippingQuoteData;
use App\Modules\Shipping\DTOs\ValidatedShippingSelection;
use App\Modules\Shipping\Exceptions\ShippingConflict;
use App\Shared\Domain\Money;
use App\Shared\Domain\PostalCode;
use App\Shared\Domain\Quantity;
use Illuminate\Validation\ValidationException;

/**
 * Recalculates everything for the checkout (RN-CHK-001, ADR-012): cart snapshot
 * (prices, quantities, stock), coupon, shipping (SHIPPING.md §7) and totals.
 * Nothing sent by the client is used as a value.
 *
 *  - strict (POST /checkout): throws at the first failure, in the API.md §3.E order;
 *  - preview (POST /checkout/preview): collects the problems in `blocking[]`.
 */
final class CheckoutCalculator
{
    public const int MAX_PENDING_ORDERS = 3;

    public function __construct(
        private readonly CartService $carts,
        private readonly CartPresenter $presenter,
        private readonly CustomerDirectory $customers,
        private readonly CouponService $coupons,
        private readonly ShippingRequestFactory $shippingRequests,
        private readonly ShippingQuoteService $shippingQuotes,
        private readonly OrderPlacement $orders,
        private readonly SettingsRepository $settings,
    ) {}

    public function compute(CheckoutData $data, bool $strict, ?CartSnapshot $snapshot = null): CheckoutComputation
    {
        $blocking = [];
        $block = static function (string $code, string $message) use (&$blocking): void {
            $blocking[] = ['code' => $code, 'message' => $message];
        };

        // 3. pending orders limit
        $pending = $this->orders->pendingOrders($data->customerId);
        if (count($pending) >= self::MAX_PENDING_ORDERS) {
            if ($strict) {
                throw TooManyPendingOrders::with($pending);
            }
            $block('too_many_pending_orders', 'Você já possui 3 pedidos aguardando pagamento.');
        }

        // 4. cart
        if ($snapshot === null) {
            $cartId = $this->carts->activeCartIdForCustomer($data->customerId);
            $snapshot = $cartId !== null ? $this->carts->snapshot($cartId, $data->customerId) : null;
        }
        if ($snapshot === null || $snapshot->isEmpty()) {
            if ($strict) {
                throw CheckoutFailed::cartEmpty();
            }
            $block('cart_empty', 'Seu carrinho está vazio.');
        }
        $invalid = $snapshot?->linesWithStatus(CartLineStatus::Unavailable, CartLineStatus::InvalidQuantity) ?? [];
        if ($invalid !== []) {
            if ($strict) {
                throw CheckoutFailed::cartInvalid(array_map(self::issue(...), $invalid));
            }
            $block('cart_invalid', 'Há itens indisponíveis ou inválidos no carrinho.');
        }

        // 5. address (IDOR: another customer's address → 422, never revealed) and profile
        $address = $this->customers->addressForCustomer($data->customerId, $data->addressUuid);
        if ($address === null) {
            throw ValidationException::withMessages(['address_uuid' => ['Endereço inválido.']]);
        }
        $customer = $this->customers->find($data->customerId);
        if ($customer === null || ! $this->customers->isProfileCompleteForCheckout($data->customerId)) {
            if ($strict) {
                throw ValidationException::withMessages(['profile' => ['Complete seu cadastro (CPF/CNPJ e dados de faturamento) para finalizar o pedido.']]);
            }
            $block('profile_incomplete', 'Complete seu cadastro para finalizar o pedido.');
        }

        // 6. stock (soft here; reserved under lock by OrderPlacement)
        $short = $snapshot?->linesWithStatus(CartLineStatus::InsufficientStock) ?? [];
        if ($short !== []) {
            if ($strict) {
                throw InsufficientStock::forItems(self::stockIssues($short));
            }
            $block('insufficient_stock', 'Estoque insuficiente para um ou mais itens.');
        }

        // coupon (evaluated before shipping: a free-shipping coupon changes the quote)
        $coupon = null;
        if ($snapshot !== null && $snapshot->couponCode !== null) {
            $coupon = $this->coupons->evaluate($snapshot->couponCode, $this->carts->toCouponContext($snapshot, null));
        }
        $discount = $coupon?->valid ? $coupon->discount : Money::zero();

        // 7. shipping (SHIPPING.md §7)
        $selection = null;
        $newQuote = null;
        if ($data->shippingQuoteId === null || $data->shippingOptionId === null) {
            $block('shipping_required', 'Escolha a forma de entrega.');
        } elseif ($snapshot !== null && ! $snapshot->isEmpty() && $invalid === []) {
            try {
                $request = $this->shippingRequests->fromLines(
                    $this->carts->toShippingLines($snapshot),
                    $address->postalCode,
                    Money::max(Money::zero(), $snapshot->subtotal->subtract($discount)),
                    (bool) ($coupon?->valid && $coupon->freeShipping),
                    $customer !== null ? new ShippingCustomer($customer->id, $customer->type->value, $customer->companyId) : null,
                    $snapshot->cartId,
                );
                $selection = $this->shippingQuotes->validateSelectionForCheckout(
                    $data->shippingQuoteId, $data->shippingOptionId, $request,
                    $this->carts->itemConfigs($snapshot), $snapshot->cartId, $data->customerId,
                );
            } catch (ShippingConflict $e) {
                if ($strict) {
                    throw $e;
                }
                $newQuote = $e->newQuote;
                $block($e->reason, $e->getMessage());
            }
        }

        // 8. coupon validity (redeemed under lock by OrderPlacement)
        if ($coupon !== null && ! $coupon->valid) {
            if ($strict) {
                $withoutCoupon = $this->build($data, $snapshot, $address, $customer, null, $selection, $newQuote, $blocking);
                $e = CouponInvalid::from($coupon, (string) $snapshot->couponCode);

                throw new CouponInvalid($e->getMessage(), details: [...$e->details(), 'summary' => $withoutCoupon->summary]);
            }
            $block('coupon_invalid', $coupon->message ?? 'Cupom inválido ou expirado.');
        }

        return $this->build($data, $snapshot, $address, $customer, $coupon, $selection, $newQuote, $blocking);
    }

    /** @param list<array{code: string, message: string}> $blocking */
    private function build(
        CheckoutData $data, ?CartSnapshot $snapshot, AddressData $address, ?CustomerData $customer,
        ?CouponEvaluation $coupon, ?ValidatedShippingSelection $selection, ?ShippingQuoteData $newQuote, array $blocking,
    ): CheckoutComputation {
        $subtotal = $snapshot?->subtotal ?? Money::zero();
        $discount = $coupon?->valid ? Money::min($coupon->discount, $subtotal) : Money::zero();
        $option = $selection?->option;
        $shippingDiscount = $option !== null ? $option->couponDiscountCents() : 0;
        $shippingGross = $option !== null ? $option->priceCents + $shippingDiscount : null;
        $total = $subtotal->subtract($discount)->add(Money::ofCents(($shippingGross ?? 0) - $shippingDiscount));
        if ($total->isNegative()) {
            $total = Money::zero();
        }

        $summary = [
            'items' => $snapshot !== null ? $this->presenter->items($snapshot) : [],
            'coupon' => $snapshot !== null ? $this->presenter->coupon($snapshot, $coupon) : null,
            'totals' => [
                'subtotal_cents' => $subtotal->cents(),
                'discount_cents' => $discount->cents(),
                'shipping_cents' => $shippingGross,
                'shipping_discount_cents' => $shippingDiscount,
                'total_cents' => $total->cents(),
            ],
            'total_weight_grams' => $selection?->totalWeightGrams ?? $snapshot?->totalWeight->grams() ?? 0,
            'address' => self::address($address),
            'shipping_option' => $option?->toPublicArray(),
            'shipping_quote' => $newQuote?->toArray(),
            'payment_method' => $data->paymentMethod->value,
            'payment_expires_in_minutes' => $this->paymentExpiryMinutes($data->paymentMethod->value),
            'billing' => $customer !== null ? self::billing($customer) : null,
            'can_place_order' => $blocking === [],
            'blocking' => $blocking,
        ];

        return new CheckoutComputation($snapshot, $address, $customer, $coupon?->valid ? $coupon : null, $selection,
            $subtotal, $discount, Money::ofCents($shippingGross ?? 0), $total, $summary);
    }

    public function paymentExpiryMinutes(string $method): int
    {
        $value = $this->settings->get(SettingKey::CheckoutPaymentExpiryMinutes);
        $minutes = is_array($value) ? (int) ($value[$method] ?? 30) : (int) ($value ?? 30);

        return $minutes > 0 ? $minutes : 30;
    }

    /** @return array<string, mixed> API.md CartItemIssue */
    public static function issue(CartLine $line): array
    {
        return [
            'cart_item_id' => $line->cartItemId,
            'variant_id' => $line->variantId,
            'sku' => $line->variant?->sku ?? '',
            'product_name' => $line->variant?->productName ?? '',
            'reason' => $line->status->value,
            'message' => $line->issueMessage ?? 'Produto indisponível.',
        ];
    }

    /**
     * @param  list<CartLine>  $lines
     * @return list<array<string, mixed>> API.md StockIssue (requested = variant total in the stock unit)
     */
    public static function stockIssues(array $lines): array
    {
        $issues = [];
        foreach ($lines as $line) {
            $warning = collect($line->warnings)->firstWhere('code', 'insufficient_stock');
            $issues[] = [
                'cart_item_id' => $line->cartItemId,
                'variant_id' => $line->variantId,
                'sku' => $line->variant?->sku ?? '',
                'product_name' => $line->variant?->productName ?? '',
                'requested_quantity' => $warning['requested_quantity'] ?? $line->billable?->stock->toNumber(),
                'available_quantity' => $warning['available_quantity'] ?? Quantity::max($line->available, Quantity::zero())->toNumber(),
            ];
        }

        return $issues;
    }

    /** @return array<string, mixed> API.md Address */
    public static function address(AddressData $a): array
    {
        $postal = PostalCode::fromString($a->postalCode);

        return [
            'uuid' => $a->uuid,
            'label' => $a->label,
            'recipient_name' => $a->recipientName,
            'phone' => $a->phone,
            'postal_code' => $postal->value(),
            'street' => $a->street,
            'number' => $a->number,
            'complement' => $a->complement,
            'district' => $a->district,
            'city' => $a->city,
            'state' => $a->state,
            'city_ibge_code' => $a->cityIbgeCode,
            'reference' => $a->reference,
            'is_default' => $a->isDefault,
            'formatted' => self::formatAddress($a),
            'created_at' => null,
        ];
    }

    public static function formatAddress(AddressData $a): string
    {
        $street = $a->street.', '.$a->number.($a->complement !== null && $a->complement !== '' ? ' ('.$a->complement.')' : '');

        return $street.' – '.$a->district.' – '.$a->city.'/'.$a->state.' – '.PostalCode::fromString($a->postalCode)->formatted();
    }

    /** @return array<string, mixed> OrderDetail['billing'] */
    public static function billing(CustomerData $c): array
    {
        $company = $c->type === CustomerType::Company;

        return [
            'customer_type' => $c->type->value,
            'name' => $c->name,
            'email' => $c->email,
            'document' => (string) $c->document(),
            'phone' => $c->phone,
            'company_name' => $company ? $c->companyLegalName : null,
            'state_registration' => $company ? ($c->stateRegistrationExempt ? 'ISENTO' : $c->stateRegistration) : null,
        ];
    }
}
