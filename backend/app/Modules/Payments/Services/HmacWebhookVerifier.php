<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentWebhookVerifier;
use App\Modules\Payments\DTOs\WebhookNotification;
use App\Modules\Payments\Enums\PaymentProvider;
use App\Modules\Payments\Exceptions\InvalidWebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Mercado Pago signature scheme (also used by the sandbox, API.md §3.F):
 * `x-signature: ts=<unix>,v1=<hex>`, v1 = HMAC-SHA256(secret,
 * "id:{data.id};request-id:{x-request-id};ts:{ts};"), compared with
 * hash_equals; |now − ts| ≤ tolerance. The previous secret is accepted during
 * rotation.
 */
final class HmacWebhookVerifier implements PaymentWebhookVerifier
{
    /** @param list<string> $secrets */
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly array $secrets,
        private readonly int $toleranceSeconds = 300,
    ) {}

    public function verify(Request $request): WebhookNotification
    {
        $secrets = array_values(array_filter($this->secrets, static fn (string $s): bool => $s !== ''));
        if ($secrets === []) {
            throw InvalidWebhookSignature::because('webhook secret not configured');
        }

        [$ts, $v1] = self::parseSignature((string) $request->header('x-signature', ''));
        $requestId = (string) $request->header('x-request-id', '');
        if ($ts === null || $v1 === null || $requestId === '') {
            throw InvalidWebhookSignature::because('missing signature parts');
        }

        $seconds = strlen($ts) > 11 ? intdiv((int) $ts, 1000) : (int) $ts;
        if (abs(CarbonImmutable::now()->getTimestamp() - $seconds) > $this->toleranceSeconds) {
            throw InvalidWebhookSignature::because('timestamp outside tolerance');
        }

        $dataId = self::dataId($request);
        if ($dataId === '') {
            throw InvalidWebhookSignature::because('missing data.id');
        }

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $valid = false;
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($v1))) {
                $valid = true;
                break;
            }
        }
        if (! $valid) {
            throw InvalidWebhookSignature::because('signature mismatch');
        }

        $body = $request->json()->all();
        $eventId = isset($body['id']) && (is_string($body['id']) || is_int($body['id'])) && (string) $body['id'] !== ''
            ? (string) $body['id']
            : hash('sha256', (string) $request->getContent());

        return new WebhookNotification(
            provider: $this->provider,
            externalEventId: mb_substr($eventId, 0, 191),
            resourceId: $dataId,
            type: mb_substr((string) ($body['action'] ?? $body['type'] ?? $body['topic'] ?? 'unknown'), 0, 100),
            occurredAt: isset($body['date_created']) && is_string($body['date_created']) ? rescue(fn () => CarbonImmutable::parse($body['date_created']), null, false) : null,
            payload: array_filter([
                'id' => $body['id'] ?? null,
                'type' => $body['type'] ?? null,
                'action' => $body['action'] ?? null,
                'data' => ['id' => $dataId],
                'date_created' => $body['date_created'] ?? null,
                'live_mode' => $body['live_mode'] ?? null,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function parseSignature(string $header): array
    {
        $ts = null;
        $v1 = null;
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 'ts' && $value !== null && ctype_digit($value)) {
                $ts = $value;
            } elseif ($key === 'v1' && $value !== null && ctype_xdigit($value)) {
                $v1 = $value;
            }
        }

        return [$ts, $v1];
    }

    /** data.id from the query string (Mercado Pago) or the body; alphanumeric ids are lower-cased. */
    private static function dataId(Request $request): string
    {
        $id = $request->query('data_id') ?? $request->query('data.id');
        if (! is_string($id) || $id === '') {
            $data = $request->json('data');
            $id = is_array($data) && isset($data['id']) && (is_string($data['id']) || is_int($data['id'])) ? (string) $data['id'] : '';
        }

        return ctype_digit($id) ? $id : strtolower($id);
    }
}
