<?php

declare(strict_types=1);

namespace App\Shared\PostalCode;

use RuntimeException;

/** The lookup service failed (timeout, network, 5xx, invalid payload). Never cached. */
class PostalCodeLookupException extends RuntimeException {}
