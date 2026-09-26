<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Shared\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** 409 stale_resource (API.md §1.9 expected_updated_at). */
final class StaleResource extends DomainException
{
    protected string $errorCode = 'stale_resource';

    protected int $httpStatus = 409;

    public static function check(?string $expected, ?DateTimeInterface $current): void
    {
        if ($expected === null || $current === null) {
            return;
        }
        if (CarbonImmutable::parse($expected)->utc()->format('Y-m-d H:i:s') !== CarbonImmutable::instance($current)->utc()->format('Y-m-d H:i:s')) {
            throw new self('Este registro foi alterado por outra pessoa. Recarregue e tente novamente.', details: [
                'current_updated_at' => CarbonImmutable::instance($current)->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
        }
    }
}
