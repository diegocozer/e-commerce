<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Enums\CancelReasonCode;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Carbon\CarbonInterface;

/** Shared formatting of order data for the customer and admin resources (API.md §2.8/§2.14). */
final class OrderPresenter
{
    public static function iso(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    public static function timelineLabel(OrderStatus $status, bool $reactivated = false): string
    {
        return match ($status) {
            OrderStatus::PendingPayment => 'Pedido realizado',
            OrderStatus::Paid => $reactivated ? 'Pagamento aprovado (pedido reativado)' : 'Pagamento aprovado',
            OrderStatus::Processing => 'Em separação',
            OrderStatus::Shipped => 'Enviado',
            OrderStatus::Delivered => 'Entregue',
            OrderStatus::ReadyForPickup => 'Pronto para retirada',
            OrderStatus::PickedUp => 'Retirado',
            OrderStatus::Cancelled => 'Cancelado',
        };
    }

    public static function cancelReasonLabel(?CancelReasonCode $code): ?string
    {
        return match ($code) {
            null => null,
            CancelReasonCode::PaymentExpired => 'Pagamento não realizado no prazo',
            CancelReasonCode::Customer => 'Cancelado por você',
            CancelReasonCode::Admin => 'Cancelado pela loja',
            CancelReasonCode::PaymentFailed => 'Pagamento recusado',
        };
    }

    public static function transitionLabel(OrderStatus $to): string
    {
        return match ($to) {
            OrderStatus::Processing => 'Marcar em separação',
            OrderStatus::Shipped => 'Marcar como enviado',
            OrderStatus::Delivered => 'Marcar como entregue',
            OrderStatus::ReadyForPickup => 'Marcar como pronto para retirada',
            OrderStatus::PickedUp => 'Registrar retirada',
            default => $to->label(),
        };
    }

    /** @return array{quantity: int|float|null, width_m: int|float|null, height_m: int|float|null, pieces: int|null} */
    public static function configuration(OrderItem $item): array
    {
        return [
            'quantity' => $item->quantity?->toNumber(),
            'width_m' => $item->width_mm !== null ? Quantity::fromMilli($item->width_mm)->toNumber() : null,
            'height_m' => $item->height_mm !== null ? Quantity::fromMilli($item->height_mm)->toNumber() : null,
            'pieces' => $item->pieces,
        ];
    }

    /** "5 m" | "1,20 m × 2,50 m × 1 peça" | "2 rolos" */
    public static function configurationLabel(OrderItem $item): string
    {
        if ($item->sale_unit === SaleUnit::SquareMeter) {
            $pieces = (int) $item->pieces;

            return sprintf('%s m × %s m × %d %s',
                Quantity::fromMilli((int) $item->width_mm)->format(2),
                Quantity::fromMilli((int) $item->height_mm)->format(2),
                $pieces, $pieces === 1 ? 'peça' : 'peças');
        }

        $quantity = $item->quantity ?? $item->billable_quantity;
        $text = self::decimal($quantity);
        $plural = $quantity->greaterThan(Quantity::ofUnits(1));

        return match ($item->sale_unit) {
            SaleUnit::Roll => $text.' '.($plural ? 'rolos' : 'rolo'),
            SaleUnit::Box => $text.' '.($plural ? 'caixas' : 'caixa'),
            SaleUnit::Unit => $text.' '.($plural ? 'unidades' : 'unidade'),
            default => $text.' '.$item->sale_unit->abbreviation(),
        };
    }

    /** "Separar: 5 m" | "Cortar: 4 peças de 1,20 × 2,50 m" */
    public static function pickingInstruction(OrderItem $item): string
    {
        if ($item->sale_unit === SaleUnit::SquareMeter) {
            $pieces = (int) $item->pieces;

            return sprintf('Cortar: %d %s de %s × %s m', $pieces, $pieces === 1 ? 'peça' : 'peças',
                Quantity::fromMilli((int) $item->width_mm)->format(2), Quantity::fromMilli((int) $item->height_mm)->format(2));
        }

        return 'Separar: '.self::configurationLabel($item);
    }

    /** pt-BR decimal without trailing zeros ("5", "5,5", "2,35"). */
    public static function decimal(Quantity $q): string
    {
        return str_replace('.', ',', $q->toTrimmedString());
    }

    /** @return array<string, mixed> OrderItem (API.md §2.8) */
    public static function item(OrderItem $item): array
    {
        $square = $item->sale_unit === SaleUnit::SquareMeter;

        return [
            'product_id' => $item->product_id,
            'product_name' => $item->product_name,
            'variant_name' => $item->variant_name,
            'sku' => $item->sku,
            'product_url_path' => null, // Catalog is not a dependency of Orders (see report)
            'sale_unit' => $item->sale_unit->value,
            'sale_unit_abbr' => $item->sale_unit->abbreviation(),
            'configuration' => self::configuration($item),
            'configuration_label' => self::configurationLabel($item),
            'billable_quantity' => $item->billable_quantity->toNumber(),
            'stock_quantity' => $item->stock_quantity->toNumber(),
            'area_m2' => $square ? $item->stock_quantity->toNumber() : null,
            'min_area_applied' => $item->billable_quantity->greaterThan($item->stock_quantity),
            'unit_price_cents' => $item->unit_price_cents,
            'base_unit_price_cents' => $item->base_unit_price_cents,
            'price_source' => $item->price_source->value,
            'subtotal_cents' => $item->subtotal_cents,
            'discount_cents' => $item->discount_cents,
            'total_cents' => $item->total_cents,
            'weight_grams' => $item->weight_grams,
        ];
    }

    public static function latestPayment(Order $order): ?Payment
    {
        return $order->relationLoaded('payments') ? $order->payments->sortByDesc('id')->first() : null;
    }

    /** @return array<string, mixed>|null OrderPayment (API.md §2.8) */
    public static function payment(?Payment $payment): ?array
    {
        if ($payment === null) {
            return null;
        }
        $pix = $payment->status === PaymentStatus::Pending && $payment->pix_copy_paste !== null;

        return [
            'uuid' => $payment->uuid,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'amount_cents' => $payment->amount_cents,
            'expires_at' => self::iso($payment->expires_at),
            'paid_at' => self::iso($payment->paid_at),
            'pix' => $pix ? [
                'qr_code_base64' => $payment->pix_qr_code_base64,
                'copy_paste' => $payment->pix_copy_paste,
                'expires_at' => self::iso($payment->expires_at),
            ] : null,
        ];
    }

    /** @return array{can_pay: bool, can_retry_payment: bool, can_cancel: bool, can_request_cancellation: bool, can_reorder: bool} */
    public static function allowedActions(Order $order): array
    {
        $pending = $order->status === OrderStatus::PendingPayment && $order->expires_at !== null && $order->expires_at->isFuture();
        $payment = self::latestPayment($order);
        $usablePix = $payment !== null && $payment->status === PaymentStatus::Pending && $payment->pix_copy_paste !== null
            && ($payment->expires_at === null || $payment->expires_at->isFuture());

        return [
            'can_pay' => $pending && $usablePix,
            'can_retry_payment' => $pending && ! $usablePix,
            'can_cancel' => $order->status === OrderStatus::PendingPayment,
            'can_request_cancellation' => in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true) && $order->cancellation_requested_at === null,
            'can_reorder' => true,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function address(Order $order): ?array
    {
        if ($order->shipping_method_type === 'pickup' || $order->shipping_street === null) {
            return null;
        }
        $formatted = trim(sprintf('%s, %s%s - %s, %s/%s - CEP %s',
            $order->shipping_street, $order->shipping_number,
            $order->shipping_complement ? ' ('.$order->shipping_complement.')' : '',
            $order->shipping_district, $order->shipping_city, $order->shipping_state,
            substr((string) $order->shipping_postal_code, 0, 5).'-'.substr((string) $order->shipping_postal_code, 5)));

        return [
            'recipient_name' => $order->shipping_recipient_name,
            'phone' => $order->shipping_phone,
            'postal_code' => $order->shipping_postal_code,
            'street' => $order->shipping_street,
            'number' => $order->shipping_number,
            'complement' => $order->shipping_complement,
            'district' => $order->shipping_district,
            'city' => $order->shipping_city,
            'state' => $order->shipping_state,
            'reference' => $order->shipping_reference,
            'formatted' => $formatted,
        ];
    }

    /** @return array<string, mixed> OrderShippingSnapshot */
    public static function shipping(Order $order): array
    {
        $min = $order->shipping_delivery_days_min;
        $max = $order->shipping_delivery_days_max;
        $label = match (true) {
            $max === null => null,
            $min === null || $min === $max => $max === 1 ? '1 dia útil' : "{$max} dias úteis",
            default => "{$min} a {$max} dias úteis",
        };

        return [
            'method_name' => $order->shipping_method_name,
            'method_type' => $order->shipping_method_type,
            'carrier_code' => $order->shipping_carrier_code,
            'service_code' => $order->shipping_service_code,
            'delivery_days_min' => $min,
            'delivery_days_max' => $max,
            'delivery_label' => $label,
            'estimated_delivery_date' => $order->estimated_delivery_date?->toDateString(),
            'tracking_code' => $order->tracking_code,
            'tracking_url' => $order->tracking_url,
            'address' => self::address($order),
            'pickup_address' => null, // Shipping is not a dependency of Orders (see report)
            'picked_up_at' => self::iso($order->picked_up_at),
            'picked_up_by_name' => $order->picked_up_by_name,
        ];
    }

    /** @return array<string, int> */
    public static function totals(Order $order): array
    {
        return [
            'subtotal_cents' => $order->subtotal_cents,
            'discount_cents' => $order->discount_cents,
            'shipping_cents' => $order->shipping_cents,
            'shipping_discount_cents' => $order->shipping_discount_cents,
            'total_cents' => $order->total_cents,
        ];
    }
}
