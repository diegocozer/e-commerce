<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentRefundStatus;
use App\Shared\Domain\Money;

/** Result of a refund call (`Succeeded`, `Processing` while the gateway settles, or `Failed`). */
final readonly class GatewayRefund
{
    /** @param array<string, mixed> $sanitized */
    public function __construct(
        public string $externalId,
        public PaymentRefundStatus $status,
        public Money $amount,
        public ?string $failureReason = null,
        public array $sanitized = [],
    ) {}
}
