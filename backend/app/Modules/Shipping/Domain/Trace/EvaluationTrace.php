<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Domain\Trace;

/** Full simulator trace (SHIPPING.md §10.1). */
final class EvaluationTrace
{
    /** @var list<array{zone_id: int, name: string, matched_by: string, specificity: string}> */
    public array $zonesMatched = [];

    /** @var list<MethodTrace> */
    public array $methods = [];

    /** @return array{zones_matched: list<array<string, mixed>>, methods: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'zones_matched' => $this->zonesMatched,
            'methods' => array_map(static fn (MethodTrace $m): array => $m->toArray(), $this->methods),
        ];
    }
}
