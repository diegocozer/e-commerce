<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * API error format (ADR-013 / ADR-020):
 *  - 422 keeps Laravel's default `{message, errors}`;
 *  - every other error is `{message, code}` (+ domain details), always JSON for /api/*.
 */
final class ApiExceptionRenderer
{
    private const array MESSAGES = [
        400 => 'Requisição inválida.',
        401 => 'Não autenticado.',
        403 => 'Acesso negado.',
        404 => 'Recurso não encontrado.',
        405 => 'Método não permitido.',
        409 => 'Conflito.',
        413 => 'Conteúdo muito grande.',
        419 => 'Sessão expirada. Recarregue a página e tente novamente.',
        429 => 'Muitas requisições. Tente novamente em instantes.',
        500 => 'Erro interno do servidor.',
        503 => 'Serviço temporariamente indisponível.',
    ];

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => self::isApi($request) || $request->expectsJson(),
        );

        $exceptions->dontReport([DomainException::class]);

        $exceptions->render(static function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::isApi($request) && ! $request->expectsJson()) {
                return null;
            }

            return self::toResponse($e);
        });
    }

    public static function isApi(Request $request): bool
    {
        return $request->is('api/*', 'api', 'sanctum/*');
    }

    public static function toResponse(Throwable $e): ?JsonResponse
    {
        if ($e instanceof ValidationException) {
            return null; // Laravel default 422 {message, errors}
        }

        if ($e instanceof DomainException) {
            return self::json($e->httpStatus(), $e->getMessage(), $e->errorCode(), $e->details());
        }

        return match (true) {
            $e instanceof AuthenticationException => self::json(401, self::MESSAGES[401], ErrorCode::Unauthenticated->value),
            $e instanceof AuthorizationException => self::json(
                $e->hasStatus() ? (int) $e->status() : 403,
                self::MESSAGES[403],
                ErrorCode::forStatus($e->hasStatus() ? (int) $e->status() : 403)->value,
            ),
            $e instanceof ModelNotFoundException => self::json(404, self::MESSAGES[404], ErrorCode::NotFound->value),
            $e instanceof TokenMismatchException => self::json(419, self::MESSAGES[419], ErrorCode::CsrfTokenMismatch->value),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::serverError($e),
        };
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $message = $e instanceof Throwable && $e->getMessage() !== '' && $status < 500 && ! in_array($status, [401, 403, 404, 405, 419, 429], true)
            ? $e->getMessage()
            : (self::MESSAGES[$status] ?? self::MESSAGES[$status >= 500 ? 500 : 400]);

        return self::json($status, $message, ErrorCode::forStatus($status)->value, headers: $e->getHeaders());
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        $body = [];
        if (config('app.debug')) {
            $body = ['exception' => $e::class, 'debug_message' => $e->getMessage()];
        }

        return self::json(500, self::MESSAGES[500], ErrorCode::ServerError->value, $body);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    private static function json(int $status, string $message, string $code, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([...$details, 'message' => $message, 'code' => $code], $status, $headers);
    }
}
