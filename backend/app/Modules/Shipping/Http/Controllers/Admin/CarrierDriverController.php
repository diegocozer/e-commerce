<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Carriers\CarrierRegistry;
use Illuminate\Http\JsonResponse;

final class CarrierDriverController
{
    public function index(CarrierRegistry $registry): JsonResponse
    {
        return new JsonResponse(['data' => array_map(static fn (array $d): array => [
            'driver' => $d['driver'],
            'name' => $d['name'],
            'settings_schema' => (object) (['timeout_ms' => 'integer', 'cubic_divisor' => 'integer', 'origin_postal_code' => 'string'] + $d['settings_schema']),
        ], $registry->drivers())]);
    }
}
