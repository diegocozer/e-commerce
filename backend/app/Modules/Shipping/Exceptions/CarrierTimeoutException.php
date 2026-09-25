<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

/** Carrier timeout / connection failure. */
class CarrierTimeoutException extends CarrierException {}
