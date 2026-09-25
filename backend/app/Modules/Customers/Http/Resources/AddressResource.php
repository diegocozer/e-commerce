<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API.md §2.7 Address (public id = uuid).
 *
 * @mixin CustomerAddress
 */
final class AddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CustomerAddress $a */
        $a = $this->resource;

        return [
            'uuid' => $a->uuid,
            'label' => $a->label,
            'recipient_name' => $a->recipient_name,
            'phone' => $a->phone,
            'postal_code' => $a->postal_code,
            'street' => $a->street,
            'number' => $a->number,
            'complement' => $a->complement,
            'district' => $a->district,
            'city' => $a->city,
            'state' => $a->state,
            'city_ibge_code' => $a->city_ibge_code,
            'reference' => $a->reference,
            'is_default' => $a->is_default,
            'formatted' => self::format($a),
            'created_at' => $a->created_at?->utc()->toIso8601ZuluString(),
        ];
    }

    /** "Rua das Palmeiras, 123 – Victor Konder – Blumenau/SC – 89012-000" */
    public static function format(CustomerAddress $a): string
    {
        $street = $a->street.', '.$a->number.($a->complement ? ' '.$a->complement : '');
        $cep = substr($a->postal_code, 0, 5).'-'.substr($a->postal_code, 5);

        return "{$street} – {$a->district} – {$a->city}/{$a->state} – {$cep}";
    }
}
