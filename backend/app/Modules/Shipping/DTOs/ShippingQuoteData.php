<?php

declare(strict_types=1);

namespace App\Modules\Shipping\DTOs;

use App\Modules\Shipping\Enums\UnavailableReason;

/**
 * Quote returned to the store (API.md §2.6 ShippingQuote). `quoteId`/`expiresAt`
 * are null for non-persisted estimates. Use toArray() for the HTTP payload.
 */
final readonly class ShippingQuoteData
{
    public const string NO_OPTIONS_MESSAGE = 'Não há opções de entrega para este CEP.';

    public const string PICKUP_ONLY_MESSAGE = 'Um ou mais itens do carrinho estão disponíveis somente para retirada.';

    /**
     * @param  list<ShippingOption>  $options
     * @param  list<UnavailableMethod>  $unavailable  internal (never serialized by toArray())
     */
    public function __construct(
        public ?string $quoteId,
        public ?\DateTimeImmutable $expiresAt,
        public Destination $destination,
        public array $options,
        public array $unavailable,
        public int $totalWeightGrams,
        public string $requestHash,
        public ?int $cartId = null,
        public ?int $customerId = null,
    ) {}

    /** "pickup_only_items" when the cart has a pickup-only item (only reason exposed publicly). */
    public function notice(): ?string
    {
        foreach ($this->unavailable as $u) {
            if ($u->reason === UnavailableReason::PickupOnlyItems) {
                return UnavailableReason::PickupOnlyItems->value;
            }
        }

        return null;
    }

    public function message(): ?string
    {
        if ($this->options !== []) {
            return null;
        }

        return $this->notice() !== null ? self::PICKUP_ONLY_MESSAGE : self::NO_OPTIONS_MESSAGE;
    }

    public function option(string $optionId): ?ShippingOption
    {
        foreach ($this->options as $option) {
            if ($option->optionId === $optionId) {
                return $option;
            }
        }

        return null;
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= ($at ?? now());
    }

    /** API.md §2.6 ShippingQuote. */
    public function toArray(): array
    {
        return [
            'quote_id' => $this->quoteId,
            'expires_at' => $this->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'destination' => $this->destination->toPublicArray(),
            'options' => array_map(static fn (ShippingOption $o): array => $o->toPublicArray(), $this->options),
            'notice' => $this->notice(),
            'message' => $this->message(),
            'total_weight_grams' => $this->totalWeightGrams,
        ];
    }
}
