<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Contracts;

use App\Modules\Shipping\DTOs\CarrierQuote;
use App\Modules\Shipping\DTOs\ShippingRequest;
use App\Modules\Shipping\Exceptions\CarrierException;
use App\Modules\Shipping\Exceptions\CarrierTimeoutException;

/** External carrier driver (SHIPPING.md §4.5 / §12). Register it in the CarrierRegistry. */
interface ShippingCarrierInterface
{
    /** Driver code, e.g. 'fake', 'correios', 'melhor_envio'. */
    public function code(): string;

    /** Cheap local pre-check (destination resolved, weight/volume limits of the service). */
    public function supports(ShippingRequest $request): bool;

    /**
     * Quotes every service of the carrier. MUST honour CarrierConfig::$timeoutMs on every
     * HTTP call (connect + total).
     *
     * @throws CarrierTimeoutException timeout/connection
     * @throws CarrierException invalid response, 4xx/5xx, credentials
     */
    public function quote(ShippingRequest $request): CarrierQuote;
}
