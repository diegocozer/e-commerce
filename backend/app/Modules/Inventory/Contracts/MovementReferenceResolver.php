<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

/**
 * Resolves labels of movement references (e.g. order number). Implemented by
 * Orders; Inventory registers NullMovementReferenceResolver ("Pedido #id") as
 * a fallback (IMPLEMENTATION_PLAN.md §5.3).
 */
interface MovementReferenceResolver
{
    /**
     * @param  list<array{type: string, id: int}>  $refs
     * @return array<string, array{label: string, id: int}> keyed "order:123"
     */
    public function resolve(array $refs): array;
}
