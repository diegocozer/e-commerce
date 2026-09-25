<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

use App\Modules\Payments\Enums\PaymentStatus;
use App\Shared\Domain\Money;
use Carbon\CarbonImmutable;

/**
 * Gateway view of a charge, mapped by the driver (no raw vendor arrays leave
 * the adapter). `$status` uses our PaymentStatus vocabulary; `$sanitized` is
 * the subset safe to store in payment_transactions.payload.
 */
final readonly class GatewayPayment
{
    /** @param array<string, mixed> $sanitized */
    public function __construct(
        public string $externalId,
        public PaymentStatus $status,
        public Money $amount,
        public string $currency,
        public ?string $externalReference,
        public ?string $pixCopyPaste = null,
        public ?string $pixQrCodeBase64 = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $approvedAt = null,
        public ?string $statusDetail = null,
        public array $sanitized = [],
    ) {}
}
