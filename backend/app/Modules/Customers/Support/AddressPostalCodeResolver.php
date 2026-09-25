<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Exceptions\PostalCodeUnavailable;
use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/** Derives city/UF/IBGE from the CEP (RN-CLI-021/022, ADR-029). */
final class AddressPostalCodeResolver
{
    public function __construct(private readonly Container $container) {}

    public function resolve(string $postalCode): PostalCodeInfo
    {
        if (! $this->container->bound(PostalCodeLookup::class)) {
            throw new PostalCodeUnavailable;
        }

        try {
            return $this->container->make(PostalCodeLookup::class)->lookup($postalCode);
        } catch (PostalCodeNotFoundException) {
            throw ValidationException::withMessages(['postal_code' => 'CEP não encontrado.']);
        } catch (PostalCodeLookupException $e) {
            throw new PostalCodeUnavailable(previous: $e);
        }
    }
}
