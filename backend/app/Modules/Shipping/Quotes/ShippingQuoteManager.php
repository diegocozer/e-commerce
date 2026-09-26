<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Quotes;

use App\Modules\Shipping\Contracts\ShippingEngine;
use App\Modules\Shipping\Contracts\ShippingQuoteService;
use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\DTOs\Destination;
use App\Modules\Shipping\DTOs\ShippingOption;
use App\Modules\Shipping\DTOs\ShippingQuoteData;
use App\Modules\Shipping\DTOs\ShippingQuoteResult;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\DTOs\UnavailableMethod;
use App\Modules\Shipping\DTOs\ValidatedShippingSelection;
use App\Modules\Shipping\Enums\ShippingMethodType;
use App\Modules\Shipping\Exceptions\ShippingConflict;
use App\Modules\Shipping\Models\ShippingQuote;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Persistence and checkout validation around the engine (SHIPPING.md §6.5/§7).
 * Quotes live 30 min (shipping.quote_ttl_minutes).
 */
final class ShippingQuoteManager implements ShippingQuoteService
{
    public function __construct(
        private readonly ShippingEngine $engine,
        private readonly QuoteHasher $hasher,
        private readonly ShippingConfigRepository $config,
        private readonly Cache $cache,
    ) {}

    public function quoteAndStore(ShippingRequest $request, array $cartItemConfigs): ShippingQuoteData
    {
        if ($request->cartId === null) {
            throw new InvalidArgumentException('quoteAndStore() requires ShippingRequest::$cartId.');
        }

        $hash = $this->hasher->hash($request, $cartItemConfigs);
        $reused = $this->reusable($request, $hash);
        if ($reused !== null) {
            return $reused;
        }

        $t0 = hrtime(true);
        $result = $this->engine->evaluate($request);

        return $this->persist($request, $result, $hash, $t0);
    }

    public function estimate(ShippingRequest $request): ShippingQuoteData
    {
        $t0 = hrtime(true);
        $result = $this->engine->evaluate($request);
        $data = new ShippingQuoteData(null, null, $request->destination, $result->options, $result->unavailable,
            $request->logistics->totalWeightGrams, $result->requestHash, $request->cartId, $request->customer?->id);
        $this->logEvaluated($request, $data, $t0);

        return $data;
    }

    public function validateForCheckout(string $quoteUuid, string $optionId, ShippingRequest $current, array $cartItemConfigs, int $cartId, int $customerId): ShippingOption
    {
        return $this->validateSelectionForCheckout($quoteUuid, $optionId, $current, $cartItemConfigs, $cartId, $customerId)->option;
    }

    public function validateSelectionForCheckout(string $quoteUuid, string $optionId, ShippingRequest $current, array $cartItemConfigs, int $cartId, int $customerId): ValidatedShippingSelection
    {
        $stored = self::isUuid($quoteUuid) ? ShippingQuote::query()->where('uuid', $quoteUuid)->first() : null;
        $currentHash = $this->hasher->hash($current, $cartItemConfigs);
        $ownQuote = $stored !== null && ($stored->customer_id === null || $stored->customer_id === $customerId);
        $weight = $current->logistics->totalWeightGrams;

        $reason = null;
        if ($stored === null || ! $ownQuote || $stored->cart_id !== $cartId) {
            $reason = 'shipping_quote_invalid';
        } elseif ($stored->isExpired(CarbonImmutable::now())) {
            $reason = 'shipping_quote_expired';
        } elseif ($stored->postal_code !== $current->destination->postalCode) {
            $reason = 'shipping_postal_code_changed';
        } elseif ($stored->request_hash !== $currentHash) {
            $reason = 'shipping_quote_changed';
        }

        $chosen = null;
        if ($stored !== null && $ownQuote) {
            foreach ($stored->options as $row) {
                if (($row['id'] ?? null) === $optionId) {
                    $chosen = ShippingOption::fromStorageArray($row);
                    break;
                }
            }
        }
        if ($reason === null && $chosen === null) {
            $reason = 'shipping_option_invalid';
        }

        if ($reason === null && $chosen !== null) {
            if ($chosen->methodType === ShippingMethodType::Carrier) {
                return new ValidatedShippingSelection($stored->uuid, $chosen, $weight);
            }
            // Local methods are ALWAYS recalculated (ADR-011), only the chosen method.
            $fresh = $this->engine->evaluate($current, false, [$chosen->methodId])->option($chosen->optionId);
            if ($fresh !== null && $fresh->priceCents === $chosen->priceCents && $fresh->deliveryDaysMax === $chosen->deliveryDaysMax) {
                return new ValidatedShippingSelection($stored->uuid, $fresh, $weight);
            }
            $reason = 'shipping_price_changed';
        }

        // Divergence: re-quote and offer the new quote.
        $newQuote = $this->persist($current, $this->engine->evaluate($current), $currentHash, hrtime(true));
        $again = $chosen === null ? null : $newQuote->option($chosen->optionId);

        if ($chosen !== null && $again !== null && $ownQuote
            && in_array($reason, ['shipping_quote_expired', 'shipping_quote_changed', 'shipping_quote_invalid'], true)
            && $again->priceCents === $chosen->priceCents) {
            return new ValidatedShippingSelection((string) $newQuote->quoteId, $again, $weight);
        }
        if ($reason === 'shipping_price_changed' && $again === null) {
            $reason = 'shipping_option_unavailable';
        }

        Log::channel('shipping')->warning('shipping.checkout.mismatch', [
            'quote_id' => $stored?->uuid, 'reason' => $reason, 'cep_prefix' => $current->destination->postalPrefix(),
            'old_price_cents' => $chosen?->priceCents, 'new_price_cents' => $again?->priceCents, 'request_id' => $current->requestId,
        ]);

        throw new ShippingConflict((string) $reason, $newQuote);
    }

    public function find(string $quoteUuid): ?ShippingQuoteData
    {
        if (! self::isUuid($quoteUuid)) {
            return null;
        }
        $quote = ShippingQuote::query()->where('uuid', $quoteUuid)->first();

        return $quote === null ? null : self::toData($quote);
    }

    public static function toData(ShippingQuote $quote): ShippingQuoteData
    {
        return new ShippingQuoteData(
            quoteId: $quote->uuid,
            expiresAt: CarbonImmutable::instance($quote->expires_at),
            destination: new Destination($quote->postal_code, $quote->city_ibge_code, $quote->city ?? null, $quote->state, $quote->city_ibge_code !== null, $quote->state === null ? null : ($quote->city_ibge_code !== null ? 'lookup' : 'cep_range')),
            options: array_map(static fn (array $o): ShippingOption => ShippingOption::fromStorageArray($o), $quote->options ?? []),
            unavailable: array_map(static fn (array $u): UnavailableMethod => UnavailableMethod::fromArray($u), $quote->unavailable ?? []),
            totalWeightGrams: (int) $quote->total_weight_grams,
            requestHash: $quote->request_hash,
            cartId: $quote->cart_id,
            customerId: $quote->customer_id,
        );
    }

    private function reusable(ShippingRequest $request, string $hash): ?ShippingQuoteData
    {
        $quote = ShippingQuote::query()
            ->where('cart_id', $request->cartId)
            ->where('request_hash', $hash)
            ->where('expires_at', '>', CarbonImmutable::now()->addMinutes(5))
            ->orderByDesc('expires_at')
            ->first();

        if ($quote === null || $quote->customer_id !== $request->customer?->id) {
            return null;
        }
        // Only when the shipping configuration did not change since (cache marker; missing ⇒ re-quote).
        if ($this->cache->get('shipping:quote-config:'.$quote->uuid) !== $this->config->version()) {
            return null;
        }

        $data = self::toData($quote);

        return new ShippingQuoteData($data->quoteId, $data->expiresAt, $request->destination, $data->options, $data->unavailable,
            $data->totalWeightGrams, $data->requestHash, $data->cartId, $data->customerId);
    }

    private function persist(ShippingRequest $request, ShippingQuoteResult $result, string $hash, int $t0): ShippingQuoteData
    {
        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('shipping.quote_ttl_minutes', 30));
        $destination = $request->destination;

        $quote = ShippingQuote::query()->create([
            'cart_id' => $request->cartId,
            'customer_id' => $request->customer?->id,
            'postal_code' => $destination->postalCode,
            'city_ibge_code' => $destination->cityIbgeCode,
            'state' => $destination->state,
            'request_hash' => $hash,
            'subtotal_cents' => $request->subtotalCents,
            'total_weight_grams' => $request->logistics->totalWeightGrams,
            'total_volume_cm3' => $request->logistics->totalVolumeCm3,
            'coupon_free_shipping' => $request->couponFreeShipping,
            'options' => array_map(static fn (ShippingOption $o): array => $o->toStorageArray(), $result->options),
            'unavailable' => array_map(static fn (UnavailableMethod $u): array => $u->toArray(), $result->unavailable),
            'expires_at' => $expiresAt,
        ]);
        $quote->refresh();
        $this->cache->put('shipping:quote-config:'.$quote->uuid, $this->config->version(), $expiresAt);

        $data = new ShippingQuoteData($quote->uuid, $expiresAt, $destination, $result->options, $result->unavailable,
            $request->logistics->totalWeightGrams, $hash, $request->cartId, $request->customer?->id);
        $this->logEvaluated($request, $data, $t0);

        return $data;
    }

    private function logEvaluated(ShippingRequest $request, ShippingQuoteData $data, int $t0): void
    {
        $context = [
            'quote_id' => $data->quoteId,
            'cart_id' => $request->cartId,
            'customer_id' => $request->customer?->id,
            'cep_prefix' => $request->destination->postalPrefix(),
            'state' => $request->destination->state,
            'resolved' => $request->destination->resolved,
            'items_count' => count($request->logistics->items),
            'total_weight_grams' => $request->logistics->totalWeightGrams,
            'volumes_count' => $request->logistics->volumesCount,
            'options_count' => count($data->options),
            'option_ids' => array_map(static fn (ShippingOption $o): string => $o->optionId, $data->options),
            'unavailable' => array_map(static fn (UnavailableMethod $u): string => $u->methodCode.':'.$u->reason->value, $data->unavailable),
            'duration_ms' => intdiv(hrtime(true) - $t0, 1_000_000),
            'request_id' => $request->requestId,
        ];
        Log::channel('shipping')->info('shipping.quote.evaluated', $context);
        if ($data->options === []) {
            Log::channel('shipping')->warning('shipping.quote.no_options', $context);
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
