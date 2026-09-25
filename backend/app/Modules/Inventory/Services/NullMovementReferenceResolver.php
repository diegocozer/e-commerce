<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Contracts\MovementReferenceResolver;

/** Fallback until Orders binds its resolver: "Pedido #id". */
final class NullMovementReferenceResolver implements MovementReferenceResolver
{
    public function resolve(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $out[$ref['type'].':'.$ref['id']] = [
                'label' => ($ref['type'] === 'order' ? 'Pedido #' : ucfirst($ref['type']).' #').$ref['id'],
                'id' => (int) $ref['id'],
            ];
        }

        return $out;
    }
}
