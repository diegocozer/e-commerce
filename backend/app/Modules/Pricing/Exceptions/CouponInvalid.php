<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Modules\Pricing\DTOs\CouponEvaluation;
use App\Shared\Exceptions\DomainException;

/** 409 coupon_invalid (ADR-028) with `coupon: {code, reason_code, message}`. Callers may add `summary`. */
final class CouponInvalid extends DomainException
{
    protected string $errorCode = 'coupon_invalid';

    protected int $httpStatus = 409;

    public ?CouponEvaluation $evaluation = null;

    public static function from(CouponEvaluation $evaluation, string $code): self
    {
        $e = new self($evaluation->message ?? 'Cupom inválido ou expirado.', details: ['coupon' => [
            'code' => $code,
            'reason_code' => $evaluation->reasonCode,
            'message' => $evaluation->message,
        ]]);
        $e->evaluation = $evaluation;

        return $e;
    }
}
