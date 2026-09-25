<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Exceptions;

use RuntimeException;

/** Carrier answered with an error / invalid payload (never leaves the CarrierHandler). */
class CarrierException extends RuntimeException {}
