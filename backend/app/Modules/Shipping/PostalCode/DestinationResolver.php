<?php

declare(strict_types=1);

namespace App\Modules\Shipping\PostalCode;

use App\Modules\Shipping\DTOs\Destination;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Normalized CEP → Destination (SHIPPING.md §6.1). Lookup failures (including
 * "not found") never fail the quote: state falls back to the static table.
 */
final class DestinationResolver
{
    public function __construct(private readonly PostalCodeLookup $lookup, private readonly PostalCodeStateResolver $states) {}

    public function resolve(string $normalizedCep): Destination
    {
        $t0 = hrtime(true);
        try {
            $info = $this->lookup->lookup($normalizedCep);

            return new Destination($normalizedCep, $info->cityIbgeCode, $info->city, $info->state, true, 'lookup');
        } catch (Throwable $e) {
            $reason = $e instanceof PostalCodeNotFoundException ? 'not_found' : ($e->getMessage() !== '' ? mb_substr($e->getMessage(), 0, 40) : 'error');
            Log::channel('shipping')->warning('shipping.postal_lookup.failed', [
                'cep_prefix' => substr($normalizedCep, 0, 5),
                'reason' => $reason,
                'duration_ms' => intdiv(hrtime(true) - $t0, 1_000_000),
            ]);
            $uf = $this->states->stateFor($normalizedCep);

            return new Destination($normalizedCep, null, null, $uf, false, $uf !== null ? 'cep_range' : null);
        }
    }
}
