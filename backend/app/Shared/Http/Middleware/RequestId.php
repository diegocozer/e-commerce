<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id (ADR-013 / ARCHITECTURE.md §10.2): reuses an incoming
 * `X-Request-Id` only when it is a valid UUID/ULID (otherwise generates a
 * ULID), stores it in Laravel's Context — included in every log record and
 * propagated to queued jobs — and returns it in the response header.
 */
final class RequestId
{
    public const string HEADER = 'X-Request-Id';

    public const string CONTEXT_KEY = 'request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $requestId = Str::isUuid($incoming) || Str::isUlid($incoming) ? $incoming : (string) Str::ulid();

        $request->headers->set(self::HEADER, $requestId);
        Context::add(self::CONTEXT_KEY, $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public static function current(): ?string
    {
        $value = Context::get(self::CONTEXT_KEY);

        return is_string($value) ? $value : null;
    }
}
