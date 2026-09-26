<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Shipping\Exceptions\ResourceInUse;
use App\Modules\Shipping\Models\ShippingCarrier;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Shared\Domain\ActorRef;
use Illuminate\Support\Facades\DB;

final class ManageCarriers
{
    public function __construct(private readonly ShippingAdminSupport $support) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ActorRef $actor): ShippingCarrier
    {
        return DB::transaction(function () use ($data, $actor): ShippingCarrier {
            $carrier = ShippingCarrier::query()->create(self::attributes($data) + ['settings' => [], 'is_active' => false])->refresh();
            $this->support->record($actor, 'shipping_carrier', 'created', $carrier->id, [], ShippingAdminSupport::snapshot($carrier) + ['has_credentials' => $carrier->credentials !== null]);

            return $carrier;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(ShippingCarrier $carrier, array $data, ActorRef $actor): ShippingCarrier
    {
        return DB::transaction(function () use ($carrier, $data, $actor): ShippingCarrier {
            $carrier = ShippingCarrier::query()->lockForUpdate()->findOrFail($carrier->id);
            $this->support->assertFresh($carrier, $data['expected_updated_at'] ?? null);
            $before = ShippingAdminSupport::snapshot($carrier) + ['has_credentials' => $carrier->credentials !== null];
            unset($data['code']);
            $carrier->update(self::attributes($data));
            $this->support->record($actor, 'shipping_carrier', 'updated', $carrier->id, $before, ShippingAdminSupport::snapshot($carrier) + ['has_credentials' => $carrier->credentials !== null]);

            return $carrier;
        });
    }

    public function delete(ShippingCarrier $carrier, ActorRef $actor): void
    {
        $methods = ShippingMethod::withTrashed()->where('carrier_id', $carrier->id)->orderBy('id')->get();
        if ($methods->isNotEmpty()) {
            throw new ResourceInUse('Transportadora usada por métodos de entrega. Desative-a em vez de excluir.', $methods->map(
                static fn (ShippingMethod $m): array => ['type' => 'shipping_method', 'id' => $m->id, 'label' => $m->name],
            )->all());
        }

        DB::transaction(function () use ($carrier, $actor): void {
            $before = ShippingAdminSupport::snapshot($carrier);
            $carrier->delete();
            $this->support->record($actor, 'shipping_carrier', 'deleted', $carrier->id, $before, []);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'code', 'driver', 'credentials', 'settings', 'is_active']));
        if (isset($attributes['settings']['origin_postal_code'])) {
            $attributes['settings']['origin_postal_code'] = preg_replace('/\D/', '', (string) $attributes['settings']['origin_postal_code']);
        }
        if (isset($attributes['settings'])) {
            $attributes['settings'] = array_filter($attributes['settings'], static fn ($v): bool => $v !== null);
        }

        return $attributes;
    }
}
