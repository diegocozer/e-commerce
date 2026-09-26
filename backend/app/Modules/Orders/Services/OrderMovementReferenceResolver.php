<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Inventory\Contracts\MovementReferenceResolver;
use App\Modules\Orders\Models\Order;

/** Labels of inventory movement references: "Pedido CV-000123" (BE-ORD-10). */
final class OrderMovementReferenceResolver implements MovementReferenceResolver
{
    public function resolve(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? null) === OrderStockReservations::REFERENCE_TYPE) {
                $ids[] = (int) $ref['id'];
            }
        }

        $result = [];
        if ($ids !== []) {
            foreach (Order::query()->whereIn('id', array_unique($ids))->get(['id', 'number']) as $order) {
                $result['order:'.$order->id] = ['label' => 'Pedido '.$order->number, 'id' => $order->id];
            }
        }
        foreach ($refs as $ref) {
            $key = ($ref['type'] ?? '').':'.($ref['id'] ?? '');
            $result[$key] ??= ['label' => ($ref['type'] ?? '') === 'order' ? 'Pedido #'.$ref['id'] : ucfirst((string) ($ref['type'] ?? '')).' #'.($ref['id'] ?? ''), 'id' => (int) ($ref['id'] ?? 0)];
        }

        return $result;
    }
}
