<?php

declare(strict_types=1);

namespace App\Shared\PostalCode;

/** The CEP does not exist in the lookup source. */
final class PostalCodeNotFoundException extends PostalCodeLookupException {}
