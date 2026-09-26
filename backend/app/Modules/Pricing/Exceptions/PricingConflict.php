<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 409 resource_in_use / stale_resource for pricing admin endpoints (API.md §1.6). */
final class PricingConflict extends DomainException
{
    protected int $httpStatus = 409;

    /** @param  list<array{type: string, id: int, label: string}>  $blockers */
    public static function inUse(string $message, array $blockers): self
    {
        return new self($message, 'resource_in_use', details: ['blockers' => $blockers]);
    }

    public static function checkStale(?string $expected, ?\DateTimeInterface $current): void
    {
        if ($expected === null || $current === null) {
            return;
        }
        $cur = \Carbon\CarbonImmutable::instance($current)->utc();
        if (\Carbon\CarbonImmutable::parse($expected)->utc()->format('Y-m-d H:i:s') !== $cur->format('Y-m-d H:i:s')) {
            throw new self('Este registro foi alterado por outra pessoa. Recarregue e tente novamente.', 'stale_resource',
                details: ['current_updated_at' => $cur->format('Y-m-d\TH:i:s\Z')]);
        }
    }
}
