<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources\Admin;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Modules\Orders\Support\OrderPresenter as P;
use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Shared\Domain\ActorType;
use App\Shared\Support\Mask;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * AdminOrder (API.md §2.14). Needs items, statusHistory, payments.transactions,
 * payments.refunds loaded. `allowed_transitions` / `can_cancel` are filtered by
 * the current admin's permissions. @mixin Order
 */
class AdminOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Order $o */
        $o = $this->resource;
        $admin = $request->user('admin');
        $can = static fn (string $p): bool => $admin instanceof Authorizable && $admin->can($p);
        $canViewPayments = $can('payments.view');

        $machine = app(OrderStateMachine::class);
        $transitions = [];
        foreach ($machine->operationalTransitions($o->status, $o->shipping_method_type) as $to) {
            $allowed = $to === OrderStatus::PickedUp
                ? $can('orders.fulfill') || $can('orders.pickup')
                : $can('orders.fulfill');
            if ($allowed) {
                $transitions[] = self::transition($to, $o->shipping_method_type);
            }
        }
        $requiresRefund = in_array($o->status, [OrderStatus::Paid, OrderStatus::Processing], true);
        $canCancel = $machine->isCancellable($o->status) && ($o->status === OrderStatus::PendingPayment
            ? $can('orders.cancel_unpaid')
            : $can('orders.cancel_paid'));

        $payments = $o->payments->sortByDesc('id')->values();
        $mismatch = $payments->contains(static fn (Payment $p): bool => $p->transactions->contains(
            static fn (PaymentTransaction $t): bool => ($t->payload['anomaly'] ?? null) === 'amount_mismatch'));

        $adminIds = $o->statusHistory->where('actor_type', ActorType::Admin)->pluck('actor_id')
            ->merge($payments->flatMap(static fn (Payment $p) => $p->transactions->pluck('admin_user_id')))
            ->filter()->unique()->values()->all();
        // Identity is not a dependency of Orders: admin names are read-only lookups.
        $adminNames = $adminIds === [] ? [] : DB::table('admin_users')->whereIn('id', $adminIds)->pluck('name', 'id')->all();

        return [
            'id' => $o->id,
            'uuid' => $o->uuid,
            'number' => $o->number,
            'status' => $o->status->value,
            'status_label' => $o->status->label(),
            'payment_status' => $o->payment_status->value,
            'payment_method' => $o->payment_method->value,
            'placed_at' => P::iso($o->placed_at),
            'expires_at' => P::iso($o->expires_at),
            'paid_at' => P::iso($o->paid_at),
            'processing_at' => P::iso($o->processing_at),
            'shipped_at' => P::iso($o->shipped_at),
            'ready_for_pickup_at' => P::iso($o->ready_for_pickup_at),
            'delivered_at' => P::iso($o->delivered_at),
            'picked_up_at' => P::iso($o->picked_up_at),
            'cancelled_at' => P::iso($o->cancelled_at),
            'refunded_at' => P::iso($o->refunded_at),
            'cancel_reason_code' => $o->cancel_reason_code?->value,
            'cancel_reason' => $o->cancel_reason,
            'cancellation_request' => $o->cancellation_requested_at === null ? null : [
                'requested_at' => P::iso($o->cancellation_requested_at),
                'reason' => $o->cancellation_request_reason,
            ],
            'customer' => [
                'id' => $o->customer_id,
                'uuid' => $o->customer?->uuid,
                'type' => $o->customer_type->value,
                'name' => $o->customer_name,
                'email' => $o->customer_email,
                'phone' => $o->customer_phone,
                'document_masked' => Mask::document($o->customer_document),
                'company_name' => $o->customer_company_name,
                'state_registration' => $o->customer_state_registration,
            ],
            'items' => $o->items->map(static fn (OrderItem $i): array => [
                ...P::item($i),
                'id' => $i->id,
                'variant_id' => $i->variant_id,
                'picking_instruction' => P::pickingInstruction($i),
            ])->values()->all(),
            'totals' => P::totals($o),
            'coupon' => $o->coupon_id === null ? null : ['id' => $o->coupon_id, 'code' => $o->coupon_code],
            'total_weight_grams' => $o->total_weight_grams,
            'total_volume_cm3' => (int) $o->total_volume_cm3,
            'shipping' => [
                ...P::shipping($o),
                'method_id' => $o->shipping_method_id,
                'rule_id' => $o->shipping_rule_id,
                'option_id' => $o->shipping_option_id,
                'quote_uuid' => $o->shipping_quote_uuid,
                'picked_up_by_document_masked' => $o->picked_up_by_document === null ? null : self::maskFree($o->picked_up_by_document),
            ],
            'payments' => $payments->map(static fn (Payment $p): array => self::payment($p, $canViewPayments, $adminNames))->all(),
            'status_history' => $o->statusHistory->sortByDesc('id')->map(static fn (OrderStatusHistory $h): array => [
                'id' => $h->id,
                'from_status' => $h->from_status?->value,
                'to_status' => $h->to_status->value,
                'actor' => [
                    'type' => $h->actor_type->value,
                    'id' => $h->actor_id,
                    'name' => match ($h->actor_type) {
                        ActorType::Admin => $adminNames[$h->actor_id] ?? null,
                        ActorType::Customer => $o->customer_name,
                        ActorType::System => null,
                    },
                ],
                'note' => $h->note,
                'created_at' => P::iso($h->created_at),
            ])->values()->all(),
            'notes' => $o->notes,
            'internal_notes' => $o->internal_notes,
            'allowed_transitions' => $transitions,
            'can_cancel' => $canCancel,
            'cancel_requires_refund' => $requiresRefund,
            'flags' => ['amount_mismatch' => $mismatch],
            'created_at' => P::iso($o->created_at),
            'updated_at' => P::iso($o->updated_at),
        ];
    }

    /** @return array<string, mixed> AdminTransition */
    private static function transition(OrderStatus $to, string $methodType): array
    {
        return [
            'to_status' => $to->value,
            'label' => P::transitionLabel($to),
            'required_fields' => match ($to) {
                OrderStatus::Shipped => $methodType === 'carrier' ? ['tracking_code'] : [],
                OrderStatus::PickedUp => ['picked_up_by_name', 'picked_up_by_document'],
                default => [],
            },
            'optional_fields' => match ($to) {
                OrderStatus::Shipped => $methodType === 'carrier'
                    ? ['note', 'tracking_url', 'carrier_name']
                    : ['note', 'tracking_code', 'tracking_url', 'carrier_name'],
                default => ['note'],
            },
        ];
    }

    /**
     * @param  array<int, string>  $adminNames
     * @return array<string, mixed> AdminPayment
     */
    private static function payment(Payment $p, bool $withTransactions, array $adminNames): array
    {
        /** @var PaymentRefund|null $refund */
        $refund = $p->refunds->sortByDesc('id')->first();

        return [
            'id' => $p->id,
            'uuid' => $p->uuid,
            'provider' => $p->provider->value,
            'method' => $p->method->value,
            'status' => $p->status->value,
            'amount_cents' => $p->amount_cents,
            'refunded_cents' => $p->refunded_cents,
            'external_id' => $p->external_id,
            'expires_at' => P::iso($p->expires_at),
            'paid_at' => P::iso($p->paid_at),
            'failed_at' => P::iso($p->failed_at),
            'refunded_at' => P::iso($p->refunded_at),
            'failure_reason' => $p->failure_reason,
            'refund' => $refund === null ? null : [
                'status' => match ($refund->status) {
                    PaymentRefundStatus::Succeeded => 'succeeded',
                    PaymentRefundStatus::Failed => 'failed',
                    default => 'pending',
                },
                'amount_cents' => $refund->amount_cents,
                'requested_at' => P::iso($refund->created_at),
            ],
            'transactions' => ! $withTransactions ? null : $p->transactions->map(static fn (PaymentTransaction $t): array => [
                'id' => $t->id,
                'type' => $t->type->value,
                'status_before' => $t->status_before?->value,
                'status_after' => $t->status_after->value,
                'amount_cents' => $t->amount_cents,
                'external_id' => $t->external_id,
                'admin_user' => $t->admin_user_id === null ? null : ['id' => $t->admin_user_id, 'name' => $adminNames[$t->admin_user_id] ?? null],
                'created_at' => P::iso($t->created_at),
            ])->values()->all(),
            'created_at' => P::iso($p->created_at),
        ];
    }

    /** Pickup document (CPF/RG free text): keep only the last 3 characters. */
    private static function maskFree(string $document): string
    {
        return str_repeat('*', max(0, mb_strlen($document) - 3)).mb_substr($document, -3);
    }
}
