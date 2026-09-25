<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

use App\Modules\Shipping\Enums\RejectionCode;

final readonly class RuleEvaluation
{
    /** @param  list<array{code: RejectionCode, detail: string}>  $reasons  every violated condition */
    public function __construct(public bool $matched, public array $reasons = []) {}

    /** @return list<RejectionCode> */
    public function codes(): array
    {
        return array_map(static fn (array $r): RejectionCode => $r['code'], $this->reasons);
    }

    public function isTemporal(): bool
    {
        foreach ($this->codes() as $code) {
            if ($code->isTemporal()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{code: string, detail: string}> */
    public function reasonsArray(): array
    {
        return array_map(static fn (array $r): array => ['code' => $r['code']->value, 'detail' => $r['detail']], $this->reasons);
    }
}
