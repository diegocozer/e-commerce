<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class of business-rule violations (ADR-020). Rendered for the API as
 * `{"message": ..., "code": ...}` (+ details) with httpStatus(); defaults to
 * 409 Conflict. Module exceptions extend it in `Modules/<M>/Exceptions`:
 *
 *   final class InsufficientStock extends DomainException
 *   {
 *       protected string $errorCode = 'insufficient_stock';
 *   }
 */
abstract class DomainException extends RuntimeException
{
    /** Machine readable snake_case code (ADR-020). */
    protected string $errorCode = 'domain_error';

    protected int $httpStatus = 409;

    /**
     * @param  array<string, mixed>  $details  extra public fields merged into the response body
     */
    public function __construct(
        string $message = '',
        ?string $errorCode = null,
        ?int $httpStatus = null,
        protected array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $this->defaultMessage(), 0, $previous);

        $this->errorCode = $errorCode ?? $this->errorCode;
        $this->httpStatus = $httpStatus ?? $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    protected function defaultMessage(): string
    {
        return 'Não foi possível concluir a operação.';
    }
}
