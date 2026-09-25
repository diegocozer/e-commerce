<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Webhook;

use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use App\Modules\Payments\Services\WebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /api/v1/webhooks/{provider} (API.md §3.F): 401 empty body when unsigned; 200 {"status":"ok"} for new and duplicate. */
final class PaymentWebhookController
{
    public function handle(Request $request, string $provider, WebhookIngestor $ingestor): JsonResponse|Response
    {
        $gateway = PaymentProvider::tryFrom($provider);
        if ($gateway === null || ($gateway === PaymentProvider::Sandbox && app()->isProduction())) {
            abort(404);
        }

        if (strlen((string) $request->getContent()) > (int) config('payments.webhook_max_body_bytes', 65536)) {
            abort(413);
        }

        try {
            $ingestor->ingest($gateway, $request);
        } catch (InvalidWebhookSignature) {
            return response('', 401);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
