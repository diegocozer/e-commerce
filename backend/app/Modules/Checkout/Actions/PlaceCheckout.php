<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Actions;

use App\Modules\Cart\Contracts\CartService;
use App\Modules\Cart\DTOs\CartLine;
use App\Modules\Cart\Models\Cart;
use App\Modules\Checkout\DTOs\CheckoutData;
use App\Modules\Checkout\Exceptions\CheckoutFailed;
use App\Modules\Checkout\Services\CheckoutCalculator;
use App\Modules\Checkout\Services\CheckoutComputation;
use App\Modules\Checkout\Services\OrderDetailPresenter;
use App\Modules\Orders\Contracts\OrderPlacement;
use App\Modules\Orders\DTOs\AddressSnapshot;
use App\Modules\Orders\DTOs\CustomerSnapshot;
use App\Modules\Orders\DTOs\OrderData;
use App\Modules\Orders\DTOs\OrderLineData;
use App\Modules\Orders\DTOs\PlaceOrderData;
use App\Modules\Orders\DTOs\ShippingSnapshot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Contracts\PaymentService;
use App\Modules\Payments\DTOs\PayerData;
use App\Modules\Payments\DTOs\PaymentData;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /checkout (API.md §3.E, ARCHITECTURE §4.3, ADR-009/021).
 *
 * Pre-check without locks (first failure responds, in the API.md order), then ONE
 * transaction: advisory lock of the customer → idempotency re-check → cart lock and
 * re-snapshot → OrderPlacement::place (order + items + history, stock reserve,
 * coupon redeem) → pending payment → cart converted. The gateway is called only
 * after the commit; a gateway failure keeps the order pending_payment (503) and a
 * retry with the same key creates the missing PIX.
 */
final class PlaceCheckout
{
    public const int ADVISORY_LOCK_NAMESPACE = 1001;

    public function __construct(
        private readonly CheckoutCalculator $calculator,
        private readonly CartService $carts,
        private readonly OrderPlacement $orders,
        private readonly PaymentService $payments,
        private readonly OrderDetailPresenter $presenter,
    ) {}

    /** @return array{result: array<string, mixed>, replayed: bool} */
    public function execute(CheckoutData $data, int $attempt = 1): array
    {
        $key = (string) $data->idempotencyKey;
        $fingerprint = $data->fingerprint();

        $existing = $this->orders->findByIdempotencyKey($data->customerId, $key);
        if ($existing !== null) {
            return $this->replay($existing, $fingerprint);
        }

        $pre = $this->calculator->compute($data, strict: true);
        $this->assertExpectedTotal($data, $pre);

        try {
            [$order, $payment, $replayOf] = DB::transaction(function () use ($data, $key, $fingerprint, $pre): array {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::ADVISORY_LOCK_NAMESPACE, $data->customerId]);

                $existing = $this->orders->findByIdempotencyKey($data->customerId, $key);
                if ($existing !== null) {
                    return [null, null, $existing];
                }

                $cartId = $pre->snapshot?->cartId;
                $locked = $cartId !== null ? Cart::query()->whereKey($cartId)->whereNull('converted_at')->lockForUpdate()->first() : null;
                if ($locked === null) {
                    throw CheckoutFailed::cartEmpty();
                }
                $snapshot = $this->carts->snapshot((int) $cartId, $data->customerId);
                if ($snapshot->hash !== $pre->snapshot->hash || ! $snapshot->subtotal->equals($pre->snapshot->subtotal)) {
                    // The cart changed after the pre-check: never re-quote shipping here (CEP
                    // lookup/HTTP must stay outside the transaction) — roll back and start over.
                    return [null, null, null];
                }

                $order = $this->orders->place($this->placeOrderData($data, $fingerprint, $pre));
                $payment = $this->payments->createPending($this->paymentRequest($order, $pre));
                $this->carts->markConverted((int) $cartId, $order->id);

                return [$order, $payment, null];
            });
        } catch (UniqueConstraintViolationException $e) {
            // race that escaped the advisory lock: orders_customer_idempotency_unique
            $existing = $this->orders->findByIdempotencyKey($data->customerId, $key);
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $fingerprint);
        }

        if ($replayOf !== null) {
            return $this->replay($replayOf, $fingerprint);
        }
        if ($order === null) {
            // cart changed between the pre-check and the lock: recalculate outside the
            // transaction (errors surface normally); after one retry ask for confirmation.
            if ($attempt >= 2) {
                throw CheckoutFailed::priceChanged($this->calculator->compute($data, strict: false)->summary);
            }

            return $this->execute($data, $attempt + 1);
        }

        $payment = $this->initiate($order, $payment);

        return ['result' => $this->presenter->result($order->id, $payment, false), 'replayed' => false];
    }

    /** Same key: same body → the same order (creating the missing PIX); different body → 409. */
    private function replay(OrderData $order, string $fingerprint): array
    {
        if (! hash_equals($order->checkoutFingerprint, $fingerprint)) {
            throw CheckoutFailed::idempotencyConflict($order->uuid, $order->number);
        }

        $payment = $this->payments->latestForOrder($order->id);
        $usable = $payment !== null && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Approved], true);
        $canPay = $order->status === OrderStatus::PendingPayment
            && ($order->expiresAt === null || $order->expiresAt->isFuture());

        if ($canPay && ! $usable) {
            $payment = DB::transaction(fn (): PaymentData => $this->payments->createPending(new PaymentRequest(
                $order->id, $order->number, $order->paymentMethod, $order->total,
                $this->payer($order), $order->expiresAt ?? CarbonImmutable::now()->addMinutes(30),
            )));
        }
        if ($canPay && $payment !== null && $payment->status === PaymentStatus::Pending && ! $payment->isInitiated()) {
            $payment = $this->initiate($order, $payment);
        }

        return ['result' => $this->presenter->result($order->id, $payment, true), 'replayed' => true];
    }

    private function initiate(OrderData $order, PaymentData $payment): PaymentData
    {
        try {
            return $this->payments->initiate($payment->id);
        } catch (PaymentGatewayUnavailable) {
            throw CheckoutFailed::gatewayUnavailable($order->uuid, $order->number);
        }
    }

    private function assertExpectedTotal(CheckoutData $data, CheckoutComputation $calc): void
    {
        if ($data->expectedTotalCents !== $calc->total->cents()) {
            throw CheckoutFailed::priceChanged($calc->summary);
        }
    }

    private function placeOrderData(CheckoutData $data, string $fingerprint, CheckoutComputation $calc): PlaceOrderData
    {
        $snapshot = $calc->snapshot;
        $customer = $calc->customer;
        $address = $calc->address;
        $option = $calc->shipping->option;

        $lines = array_map(static function (CartLine $l): OrderLineData {
            $v = $l->variant;
            $b = $l->billable;
            $p = $l->price;

            return new OrderLineData(
                variantId: $v->id,
                productId: $v->productId,
                productName: $v->productName,
                variantName: $v->name,
                sku: $v->sku,
                saleUnit: $v->saleUnit,
                quantity: $l->input->quantity,
                widthMm: $b->widthMm ?? $l->input->widthMm,
                heightMm: $b->heightMm ?? $l->input->heightMm,
                pieces: $b->pieces ?? $l->input->pieces,
                billableQuantity: $b->billable,
                stockQuantity: $b->stock,
                baseUnitPrice: $p->baseUnitPrice,
                unitPrice: $p->unitPrice,
                priceSource: $p->source,
                subtotal: $p->lineTotal,
                weightGrams: $l->weight->grams(),
                priceListId: $p->priceListId,
                promotionId: $p->promotionId,
            );
        }, $snapshot->priceableLines());

        $billing = CheckoutCalculator::billing($customer);

        return new PlaceOrderData(
            customerId: $data->customerId,
            idempotencyKey: (string) $data->idempotencyKey,
            fingerprint: $fingerprint,
            customer: new CustomerSnapshot(
                $customer->type, $customer->name, $customer->email, (string) $customer->document(),
                $customer->phone, $billing['company_name'], $billing['state_registration'],
            ),
            shippingAddress: new AddressSnapshot(
                $address->id, $address->recipientName, $address->phone, $address->postalCode, $address->street,
                $address->number, $address->complement, $address->district, $address->city, $address->state,
                $address->cityIbgeCode, $address->reference,
            ),
            lines: $lines,
            shipping: new ShippingSnapshot(
                optionId: $option->optionId,
                methodName: $option->name,
                methodType: $option->methodType->value,
                methodId: $option->methodId,
                ruleId: $option->ruleId,
                carrierCode: $option->carrier?->code,
                serviceCode: $option->carrier?->serviceCode,
                deliveryDaysMin: $option->deliveryDaysMin,
                deliveryDaysMax: $option->deliveryDaysMax,
                quoteUuid: $calc->shipping->quoteId,
                totalWeightGrams: $calc->shipping->totalWeightGrams,
            ),
            coupon: $calc->coupon,
            subtotal: $calc->subtotal,
            discount: $calc->discount,
            shippingTotal: $calc->shippingGross,
            total: $calc->total,
            paymentMethod: $data->paymentMethod,
            expiresAt: CarbonImmutable::now()->addMinutes($this->calculator->paymentExpiryMinutes($data->paymentMethod->value)),
            notes: $data->notes,
            couponContext: $calc->coupon !== null ? $this->carts->toCouponContext($snapshot, null) : null,
            placedIp: $data->ip,
        );
    }

    private function paymentRequest(OrderData $order, CheckoutComputation $calc): PaymentRequest
    {
        return new PaymentRequest(
            $order->id, $order->number, $order->paymentMethod, $order->total,
            new PayerData($calc->customer->name, $calc->customer->email, $calc->customer->document()),
            $order->expiresAt ?? CarbonImmutable::now()->addMinutes(30),
        );
    }

    private function payer(OrderData $order): PayerData
    {
        $row = DB::table('orders')->where('id', $order->id)->first(['customer_name', 'customer_email', 'customer_document']);

        return new PayerData((string) $row->customer_name, (string) $row->customer_email, $row->customer_document);
    }
}
