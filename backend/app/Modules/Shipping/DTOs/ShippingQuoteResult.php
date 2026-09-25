<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Shipping\Domain\Trace\EvaluationTrace;

/** Full engine evaluation (SHIPPING.md §3). Not persisted by the engine. */
final readonly class ShippingQuoteResult
{
    /**
     * @param  list<ShippingOption>  $options  already sorted
     * @param  list<UnavailableMethod>  $unavailable
     */
    public function __construct(
        public ?string $quoteId,
        public ?\DateTimeImmutable $expiresAt,
        public Destination $destination,
        public array $options,
        public array $unavailable,
        public string $requestHash,
        public ?EvaluationTrace $trace = null,
    ) {}

    public function option(string $optionId): ?ShippingOption
    {
        foreach ($this->options as $option) {
            if ($option->optionId === $optionId) {
                return $option;
            }
        }

        return null;
    }
}
