<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Engine;

/** Delivery estimate texts in business days (SHIPPING.md §8). */
final class DeliveryLabelFormatter
{
    public function delivery(int $min, int $max): string
    {
        if ($max <= 0) {
            return 'Entrega no mesmo dia útil';
        }
        if ($min === $max) {
            return $max === 1 ? '1 dia útil' : "{$max} dias úteis";
        }

        return "{$min} a {$max} dias úteis";
    }

    public function pickup(int $min, int $max): string
    {
        if ($max <= 0) {
            return 'Disponível no mesmo dia útil após o pagamento';
        }
        if ($min === $max || $min <= 0) {
            return $max === 1 ? 'Disponível em 1 dia útil após o pagamento' : "Disponível em {$max} dias úteis após o pagamento";
        }

        return "Disponível em {$min} a {$max} dias úteis após o pagamento";
    }
}
