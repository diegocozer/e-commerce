<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\DomainException;

/** Gateway timeout/error (503 payment_gateway_unavailable). Callers may add `order: {uuid, number}` via details. */
final class PaymentGatewayUnavailable extends DomainException
{
    protected string $errorCode = 'payment_gateway_unavailable';

    protected int $httpStatus = 503;

    protected function defaultMessage(): string
    {
        return 'Não foi possível gerar o pagamento agora. Tente novamente em instantes.';
    }

    /** @param array<string, mixed> $details */
    public function withDetails(array $details): self
    {
        return new self($this->getMessage(), details: [...$this->details(), ...$details], previous: $this->getPrevious());
    }
}
