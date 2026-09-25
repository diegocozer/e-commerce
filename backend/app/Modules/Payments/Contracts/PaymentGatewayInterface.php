<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\GatewayPayment;
use App\Modules\Payments\DTOs\GatewayRefund;
use App\Modules\Payments\DTOs\PaymentRequest;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentGatewayUnavailable;
use App\Shared\Domain\Money;

/** Payment gateway driver (ADR-010, ARCHITECTURE.md §7.1). */
interface PaymentGatewayInterface
{
    /** @throws PaymentGatewayUnavailable */
    public function createPayment(PaymentRequest $request, string $idempotencyKey): GatewayPayment;

    /** @throws PaymentGatewayUnavailable */
    public function getPayment(string $externalId): GatewayPayment;

    /** @throws PaymentGatewayUnavailable */
    public function refund(string $externalId, ?Money $amount, string $idempotencyKey): GatewayRefund;

    public function supports(PaymentMethod $method): bool;
}
