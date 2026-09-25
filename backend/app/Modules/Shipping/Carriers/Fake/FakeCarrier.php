<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Carriers\Fake;

use App\Modules\Shipping\Contracts\ShippingCarrierInterface;
use App\Modules\Shipping\DTOs\CarrierConfig;
use App\Modules\Shipping\DTOs\CarrierQuote;
use App\Modules\Shipping\DTOs\CarrierServiceQuote;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Exceptions\CarrierException;
use App\Modules\Shipping\Exceptions\CarrierTimeoutException;
use App\Modules\Shipping\Support\MonotonicClock;

/**
 * Deterministic carrier for dev and tests (SHIPPING.md §4.5). Settings:
 * {"mode": "ok"|"timeout"|"error", "delay_ms": 0, "max_weight_grams": null,
 *  "services": [{"code","name","price_cents","days_min","days_max","error"?}]}.
 * `timeout` throws without sleeping; `delay_ms` is honoured through the
 * MonotonicClock (virtual in tests) and becomes a timeout above timeout_ms.
 */
final class FakeCarrier implements ShippingCarrierInterface
{
    public int $calls = 0;

    public function __construct(private readonly CarrierConfig $config, private readonly MonotonicClock $clock) {}

    public function code(): string
    {
        return 'fake';
    }

    public function supports(ShippingRequest $request): bool
    {
        $max = $this->config->settings['max_weight_grams'] ?? null;

        return $max === null || $request->logistics->totalWeightGrams <= (int) $max;
    }

    public function quote(ShippingRequest $request): CarrierQuote
    {
        $this->calls++;
        $settings = $this->config->settings;
        $delay = (int) ($settings['delay_ms'] ?? 0);

        if ($delay > $this->config->timeoutMs) {
            $this->clock->sleepMs($this->config->timeoutMs);
            throw new CarrierTimeoutException('Fake carrier timed out.');
        }
        $this->clock->sleepMs($delay);

        return match ($settings['mode'] ?? 'ok') {
            'timeout' => throw new CarrierTimeoutException('Fake carrier timeout.'),
            'error' => throw new CarrierException('Fake carrier error (HTTP 500).'),
            default => new CarrierQuote($this->config->code, array_map(
                static fn (array $s): CarrierServiceQuote => new CarrierServiceQuote(
                    (string) $s['code'],
                    (string) ($s['name'] ?? $s['code']),
                    isset($s['error']) ? null : (isset($s['price_cents']) ? (int) $s['price_cents'] : null),
                    isset($s['days_min']) ? (int) $s['days_min'] : null,
                    isset($s['days_max']) ? (int) $s['days_max'] : null,
                    $s['error'] ?? null,
                ),
                array_values((array) ($settings['services'] ?? [['code' => 'EXP', 'name' => 'Expresso', 'price_cents' => 3990, 'days_min' => 3, 'days_max' => 5]])),
            )),
        };
    }
}
