<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Payments\DTOs\PaymentData;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Domain\PostalCode;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * API.md §2.8/§2.9 CheckoutResult (OrderDetail + OrderPayment) built from the
 * order (read-only Eloquent access to Orders — ARCHITECTURE §2.3 rule 2) and
 * the PaymentData returned by PaymentService.
 */
final class OrderDetailPresenter
{
    /** @return array{order: array<string, mixed>, payment: array<string, mixed>|null, replayed: bool} */
    public function result(int $orderId, ?PaymentData $payment, bool $replayed): array
    {
        /** @var Order $order */
        $order = Order::query()->with(['items', 'statusHistory'])->findOrFail($orderId);
        $paymentArray = $payment !== null ? self::payment($payment) : null;

        return ['order' => $this->order($order, $payment, $paymentArray), 'payment' => $paymentArray, 'replayed' => $replayed];
    }

    /** @return array<string, mixed> */
    private function order(Order $o, ?PaymentData $payment, ?array $paymentArray): array
    {
        $pending = $o->status === OrderStatus::PendingPayment;
        $notExpired = $o->expires_at === null || $o->expires_at->isFuture();
        $usablePix = $payment?->hasUsablePix() ?? false;
        $isPickup = $o->shipping_method_type === 'pickup';

        return [
            'uuid' => $o->uuid,
            'number' => $o->number,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'payment_status' => $o->payment_status->value,
            'payment_method' => $o->payment_method->value,
            'placed_at' => self::iso($o->placed_at),
            'expires_at' => self::iso($o->expires_at),
            'items_count' => $o->items->count(),
            'total_cents' => $o->total_cents,
            'shipping_method_name' => $o->shipping_method_name,
            'shipping_method_type' => $o->shipping_method_type,
            'tracking_code' => $o->tracking_code,
            'allowed_actions' => [
                'can_pay' => $pending && $notExpired && $usablePix,
                'can_retry_payment' => $pending && $notExpired && ! $usablePix,
                'can_cancel' => $pending,
                'can_request_cancellation' => false,
                'can_reorder' => true,
            ],
            'paid_at' => self::iso($o->paid_at),
            'cancelled_at' => self::iso($o->cancelled_at),
            'cancel_reason_code' => $o->cancel_reason_code?->value,
            'cancel_reason_label' => null,
            'cancellation_request' => null,
            'items' => $o->items->map(fn (OrderItem $i): array => self::item($i))->all(),
            'totals' => [
                'subtotal_cents' => $o->subtotal_cents,
                'discount_cents' => $o->discount_cents,
                'shipping_cents' => $o->shipping_cents,
                'shipping_discount_cents' => $o->shipping_discount_cents,
                'total_cents' => $o->total_cents,
            ],
            'coupon_code' => $o->coupon_code,
            'total_weight_grams' => $o->total_weight_grams,
            'billing' => [
                'customer_type' => $o->customer_type->value,
                'name' => $o->customer_name,
                'email' => $o->customer_email,
                'document' => $o->customer_document,
                'phone' => $o->customer_phone,
                'company_name' => $o->customer_company_name,
                'state_registration' => $o->customer_state_registration,
            ],
            'shipping' => [
                'method_name' => $o->shipping_method_name,
                'method_type' => $o->shipping_method_type,
                'carrier_code' => $o->shipping_carrier_code,
                'service_code' => $o->shipping_service_code,
                'delivery_days_min' => $o->shipping_delivery_days_min,
                'delivery_days_max' => $o->shipping_delivery_days_max,
                'delivery_label' => self::deliveryLabel($o->shipping_delivery_days_min, $o->shipping_delivery_days_max, $isPickup),
                'estimated_delivery_date' => $o->estimated_delivery_date?->format('Y-m-d'),
                'tracking_code' => $o->tracking_code,
                'tracking_url' => $o->tracking_url,
                'address' => $isPickup || $o->shipping_postal_code === null ? null : [
                    'recipient_name' => $o->shipping_recipient_name,
                    'phone' => $o->shipping_phone,
                    'postal_code' => $o->shipping_postal_code,
                    'street' => $o->shipping_street,
                    'number' => $o->shipping_number,
                    'complement' => $o->shipping_complement,
                    'district' => $o->shipping_district,
                    'city' => $o->shipping_city,
                    'state' => $o->shipping_state,
                    'reference' => $o->shipping_reference,
                    'formatted' => $o->shipping_street.', '.$o->shipping_number
                        .($o->shipping_complement ? ' ('.$o->shipping_complement.')' : '')
                        .' – '.$o->shipping_district.' – '.$o->shipping_city.'/'.$o->shipping_state
                        .' – '.PostalCode::fromString((string) $o->shipping_postal_code)->formatted(),
                ],
                'pickup_address' => null,
                'picked_up_at' => self::iso($o->picked_up_at),
                'picked_up_by_name' => $o->picked_up_by_name,
            ],
            'payment' => $paymentArray,
            'timeline' => $o->statusHistory->map(static fn (OrderStatusHistory $h): array => [
                'status' => $h->to_status->value,
                'status_label' => $h->to_status === OrderStatus::PendingPayment ? 'Pedido realizado' : $h->to_status->label(),
                'occurred_at' => self::iso($h->created_at),
                'note' => null,
            ])->all(),
            'notes' => $o->notes,
        ];
    }

    /** @return array<string, mixed> API.md OrderPayment */
    public static function payment(PaymentData $p): array
    {
        return [
            'uuid' => $p->uuid,
            'method' => $p->method->value,
            'status' => $p->status->value,
            'amount_cents' => $p->amount->cents(),
            'expires_at' => self::iso($p->expiresAt),
            'paid_at' => self::iso($p->paidAt),
            'pix' => $p->pixCopyPaste !== null && in_array($p->status, [PaymentStatus::Pending, PaymentStatus::Approved], true) ? [
                'qr_code_base64' => $p->pixQrCodeBase64,
                'copy_paste' => $p->pixCopyPaste,
                'expires_at' => self::iso($p->expiresAt),
            ] : null,
        ];
    }

    /** @return array{quantity: int|float|null, width_m: int|float|null, height_m: int|float|null, pieces: int|null} LineConfiguration */
    public static function configuration(OrderItem $i): array
    {
        $m = static fn (?int $mm): int|float|null => $mm !== null ? Quantity::fromMilli($mm)->toNumber() : null;

        return [
            'quantity' => $i->quantity?->toNumber(),
            'width_m' => $m($i->width_mm),
            'height_m' => $m($i->height_mm),
            'pieces' => $i->pieces,
        ];
    }

    /** "5 m" | "1,20 m × 2,50 m × 1 peça" */
    public static function configurationLabel(OrderItem $i): string
    {
        return $i->sale_unit === SaleUnit::SquareMeter
            ? Quantity::fromMilli((int) $i->width_mm)->format().' m × '.Quantity::fromMilli((int) $i->height_mm)->format().' m × '
                .$i->pieces.((int) $i->pieces === 1 ? ' peça' : ' peças')
            : rtrim(rtrim(($i->quantity ?? $i->billable_quantity)->format(3), '0'), ',').' '.$i->sale_unit->abbreviation();
    }

    /** @return array<string, mixed> */
    private static function item(OrderItem $i): array
    {
        $isArea = $i->sale_unit === SaleUnit::SquareMeter;

        return [
            'product_id' => $i->product_id,
            'product_name' => $i->product_name,
            'variant_name' => $i->variant_name,
            'sku' => $i->sku,
            'product_url_path' => null,
            'sale_unit' => $i->sale_unit->value,
            'sale_unit_abbr' => $i->sale_unit->abbreviation(),
            'configuration' => self::configuration($i),
            'configuration_label' => self::configurationLabel($i),
            'billable_quantity' => $i->billable_quantity->toNumber(),
            'stock_quantity' => $i->stock_quantity->toNumber(),
            'area_m2' => $isArea ? $i->stock_quantity->toNumber() : null,
            'min_area_applied' => $i->billable_quantity->greaterThan($i->stock_quantity),
            'unit_price_cents' => $i->unit_price_cents,
            'base_unit_price_cents' => $i->base_unit_price_cents,
            'price_source' => $i->price_source->value,
            'subtotal_cents' => $i->subtotal_cents,
            'discount_cents' => $i->discount_cents,
            'total_cents' => $i->total_cents,
            'weight_grams' => $i->weight_grams,
        ];
    }

    private static function deliveryLabel(?int $min, ?int $max, bool $pickup): ?string
    {
        if ($min === null || $max === null) {
            return null;
        }
        if ($pickup) {
            return 'Disponível em '.$max.' dia'.($max === 1 ? '' : 's').' út'.($max === 1 ? 'il' : 'eis').' após o pagamento';
        }

        return $min === $max
            ? $max.' dia'.($max === 1 ? ' útil' : 's úteis')
            : $min.' a '.$max.' dias úteis';
    }

    private static function iso(?DateTimeInterface $at): ?string
    {
        return $at !== null ? CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
