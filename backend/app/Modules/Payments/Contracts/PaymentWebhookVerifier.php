<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTOs\WebhookNotification;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use Illuminate\Http\Request;

/** Webhook signature verification, separate from the gateway (ARCHITECTURE.md §7.1). */
interface PaymentWebhookVerifier
{
    /**
     * Returns the essentials for dedupe and processing.
     *
     * @throws InvalidWebhookSignature
     */
    public function verify(Request $request): WebhookNotification;
}
