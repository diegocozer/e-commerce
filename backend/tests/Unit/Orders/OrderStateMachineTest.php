<?php

declare(strict_types=1);

namespace Tests\Unit\Orders;

use App\Modules\Orders\Enums\OrderStatus as S;
use App\Modules\Orders\Exceptions\InvalidOrderTransition;
use App\Modules\Orders\Services\OrderStateMachine;
use App\Shared\Domain\ActorRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $sm;

    protected function setUp(): void
    {
        $this->sm = new OrderStateMachine;
    }

    /** @return iterable<string, array{S, S}> */
    public static function allowed(): iterable
    {
        yield 'pay' => [S::PendingPayment, S::Paid];
        yield 'cancel pending' => [S::PendingPayment, S::Cancelled];
        yield 'processing' => [S::Paid, S::Processing];
        yield 'cancel paid' => [S::Paid, S::Cancelled];
        yield 'ship' => [S::Processing, S::Shipped];
        yield 'ready' => [S::Processing, S::ReadyForPickup];
        yield 'cancel processing' => [S::Processing, S::Cancelled];
        yield 'deliver' => [S::Shipped, S::Delivered];
        yield 'pickup' => [S::ReadyForPickup, S::PickedUp];
        yield 'reactivate' => [S::Cancelled, S::Paid];
    }

    #[DataProvider('allowed')]
    public function test_allowed_transitions(S $from, S $to): void
    {
        self::assertTrue($this->sm->canTransition($from, $to));
    }

    /** @return iterable<string, array{S, S}> */
    public static function forbidden(): iterable
    {
        yield 'skip payment' => [S::PendingPayment, S::Shipped];
        yield 'same status' => [S::Shipped, S::Shipped];
        yield 'cancel shipped' => [S::Shipped, S::Cancelled];
        yield 'delivered back' => [S::Delivered, S::Processing];
        yield 'cancelled to processing' => [S::Cancelled, S::Processing];
        yield 'picked up to delivered' => [S::PickedUp, S::Delivered];
        yield 'paid back to pending' => [S::Paid, S::PendingPayment];
    }

    #[DataProvider('forbidden')]
    public function test_forbidden_transitions_throw_409(S $from, S $to): void
    {
        self::assertFalse($this->sm->canTransition($from, $to));
        try {
            $this->sm->assertTransition($from, $to);
            self::fail('expected exception');
        } catch (InvalidOrderTransition $e) {
            self::assertSame('invalid_status_transition', $e->errorCode());
            self::assertSame(409, $e->httpStatus());
            self::assertArrayHasKey('allowed_transitions', $e->details());
        }
    }

    public function test_actor_rules(): void
    {
        $customer = ActorRef::customer(1);
        $admin = ActorRef::admin(1);
        $system = ActorRef::system();

        self::assertTrue($this->sm->canTransition(S::PendingPayment, S::Cancelled, $customer));
        self::assertFalse($this->sm->canTransition(S::Paid, S::Cancelled, $customer));
        self::assertFalse($this->sm->canTransition(S::Paid, S::Processing, $customer));
        self::assertFalse($this->sm->canTransition(S::PendingPayment, S::Paid, $admin), 'manual payment is never allowed');
        self::assertFalse($this->sm->canTransition(S::Cancelled, S::Paid, $admin), 'reactivation is system-only');
        self::assertTrue($this->sm->canTransition(S::Cancelled, S::Paid, $system));
        self::assertTrue($this->sm->canTransition(S::PendingPayment, S::Paid, $system));
        self::assertFalse($this->sm->canTransition(S::Paid, S::Processing, $system));
        self::assertTrue($this->sm->canTransition(S::Paid, S::Cancelled, $admin));
    }

    public function test_shipping_method_rules(): void
    {
        self::assertFalse($this->sm->canTransition(S::Processing, S::Shipped, null, 'pickup'));
        self::assertTrue($this->sm->canTransition(S::Processing, S::ReadyForPickup, null, 'pickup'));
        self::assertFalse($this->sm->canTransition(S::Processing, S::ReadyForPickup, null, 'carrier'));
        self::assertSame([S::Shipped], $this->sm->operationalTransitions(S::Processing, 'own_delivery'));
        self::assertSame([S::ReadyForPickup], $this->sm->operationalTransitions(S::Processing, 'pickup'));
        self::assertSame([], $this->sm->operationalTransitions(S::PendingPayment, 'own_delivery'));
    }

    public function test_message_uses_labels(): void
    {
        $e = InvalidOrderTransition::between(S::Shipped, S::Shipped, [S::Delivered]);
        self::assertSame('Transição de status inválida: enviado → enviado.', $e->getMessage());
        self::assertSame(['delivered'], $e->details()['allowed_transitions']);
    }
}
