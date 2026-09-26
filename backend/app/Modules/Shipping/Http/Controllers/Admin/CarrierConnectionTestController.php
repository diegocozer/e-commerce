<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Shipping\Carriers\CarrierRegistry;
use App\Modules\Shipping\Domain\Config\ShippingConfigRepository;
use App\Modules\Shipping\DTOs\CartLogistics;
use App\Modules\Shipping\DTOs\Destination;
use App\Modules\Shipping\DTOs\ShippingPackage;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Engine\Handlers\CarrierHandler;
use App\Modules\Shipping\Models\ShippingCarrier;
use Illuminate\Http\JsonResponse;
use Throwable;

/** POST /admin/shipping/carriers/{id}/test — quotes a fixed 1 kg box to São Paulo. */
final class CarrierConnectionTestController
{
    public function store(ShippingCarrier $carrier, CarrierRegistry $registry, ShippingConfigRepository $config): JsonResponse
    {
        $t0 = hrtime(true);
        $package = new ShippingPackage(200, 200, 200, 1000, 0);
        $request = new ShippingRequest(
            new Destination('01310100', '3550308', 'São Paulo', 'SP', true, 'lookup'),
            new CartLogistics([$package], [], 1000, $package->volumeCm3(), 1, 200, false, false),
            10000,
            false,
        );

        try {
            $quote = $registry->make($config->carrier($carrier))->quote($request);
            $ok = true;
            $message = count($quote->services).' serviço(s) cotado(s).';
        } catch (Throwable $e) {
            $ok = false;
            $message = CarrierHandler::sanitize($e->getMessage());
        }

        return new JsonResponse(['data' => ['ok' => $ok, 'duration_ms' => intdiv(hrtime(true) - $t0, 1_000_000), 'message' => $message]]);
    }
}
