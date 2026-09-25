<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Shared\Domain\ActorRef;
use App\Shared\Domain\ActorType;

/**
 * Single source of truth of the order state machine (ADR-008, ADR-022,
 * API.md §3.G.9, RN-PED-010…018).
 *
 *   pending_payment → paid                 system only (payment flow)
 *   pending_payment → cancelled            customer (owner), admin, system (expiry)
 *   paid | processing → cancelled          admin (with refund)
 *   paid → processing                      admin
 *   processing → shipped                   admin, method ≠ pickup
 *   processing → ready_for_pickup          admin, method = pickup
 *   shipped → delivered                    admin
 *   ready_for_pickup → picked_up           admin
 *   cancelled → paid                       system only (late payment reactivation)
 */
final class OrderStateMachine
{
    /** @var array<string, list<OrderStatus>> */
    private const array GRAPH = [
        'pending_payment' => [OrderStatus::Paid, OrderStatus::Cancelled],
        'paid' => [OrderStatus::Processing, OrderStatus::Cancelled],
        'processing' => [OrderStatus::Shipped, OrderStatus::ReadyForPickup, OrderStatus::Cancelled],
        'shipped' => [OrderStatus::Delivered],
        'ready_for_pickup' => [OrderStatus::PickedUp],
        'delivered' => [],
        'picked_up' => [],
        'cancelled' => [OrderStatus::Paid],
    ];

    /** Transitions of the operational admin endpoint (POST /admin/orders/{id}/transitions). */
    public const array OPERATIONAL = [
        OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered,
        OrderStatus::ReadyForPickup, OrderStatus::PickedUp,
    ];

    public function canTransition(OrderStatus $from, OrderStatus $to, ?ActorRef $actor = null, ?string $shippingMethodType = null): bool
    {
        if (! in_array($to, self::GRAPH[$from->value], true)) {
            return false;
        }

        if ($shippingMethodType !== null) {
            if ($to === OrderStatus::Shipped && $shippingMethodType === 'pickup') {
                return false;
            }
            if ($to === OrderStatus::ReadyForPickup && $shippingMethodType !== 'pickup') {
                return false;
            }
        }

        if ($actor === null) {
            return true;
        }

        return match ($actor->type) {
            // Payment approval and late payment reactivation belong to the system.
            ActorType::System => $to === OrderStatus::Paid || ($from === OrderStatus::PendingPayment && $to === OrderStatus::Cancelled),
            // The customer only cancels his own pending order (RN-PED-020).
            ActorType::Customer => $from === OrderStatus::PendingPayment && $to === OrderStatus::Cancelled,
            ActorType::Admin => $to !== OrderStatus::Paid,
        };
    }

    /** @throws InvalidOrderTransition (409) */
    public function assertTransition(OrderStatus $from, OrderStatus $to, ?ActorRef $actor = null, ?string $shippingMethodType = null): void
    {
        if (! $this->canTransition($from, $to, $actor, $shippingMethodType)) {
            throw InvalidOrderTransition::between($from, $to, $this->allowedTransitions($from, $actor, $shippingMethodType));
        }
    }

    /** @return list<OrderStatus> */
    public function allowedTransitions(OrderStatus $from, ?ActorRef $actor = null, ?string $shippingMethodType = null): array
    {
        return array_values(array_filter(
            self::GRAPH[$from->value],
            fn (OrderStatus $to): bool => $this->canTransition($from, $to, $actor, $shippingMethodType),
        ));
    }

    /**
     * Operational transitions (without cancel) for the admin endpoint.
     *
     * @return list<OrderStatus>
     */
    public function operationalTransitions(OrderStatus $from, string $shippingMethodType): array
    {
        return array_values(array_filter(
            $this->allowedTransitions($from, null, $shippingMethodType),
            static fn (OrderStatus $to): bool => in_array($to, self::OPERATIONAL, true),
        ));
    }

    public function isCancellable(OrderStatus $status): bool
    {
        return in_array(OrderStatus::Cancelled, self::GRAPH[$status->value], true);
    }
}
