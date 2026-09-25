<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentWebhookVerifier;
use App\Modules\Payments\Enums\PaymentProvider;

/** Resolves the verifier (secret + previous secret) of each provider. */
final class WebhookVerifierFactory
{
    /** @var array<string, PaymentWebhookVerifier> */
    private array $overrides = [];

    public function for(PaymentProvider $provider): PaymentWebhookVerifier
    {
        if (isset($this->overrides[$provider->value])) {
            return $this->overrides[$provider->value];
        }

        $config = (array) config('payments.drivers.'.$provider->value, []);

        return new HmacWebhookVerifier(
            $provider,
            [(string) ($config['webhook_secret'] ?? ''), (string) ($config['webhook_secret_previous'] ?? '')],
            (int) config('payments.webhook_tolerance_seconds', 300),
        );
    }

    /** Tests: replace a provider's verifier. */
    public function extend(PaymentProvider $provider, PaymentWebhookVerifier $verifier): void
    {
        $this->overrides[$provider->value] = $verifier;
    }

    /**
     * Builds the signature headers for a payload (sandbox dev endpoints and tests).
     *
     * @return array{x-signature: string, x-request-id: string}
     */
    public static function sign(string $secret, string $dataId, string $requestId, int $ts): array
    {
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";

        return ['x-signature' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, $secret), 'x-request-id' => $requestId];
    }
}
