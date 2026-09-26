<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Store;

use App\Modules\Shipping\Exceptions\PostalCodeLookupUnavailable;
use App\Modules\Shipping\Exceptions\PostalCodeNotFound;
use App\Modules\Shipping\PostalCode\CachedPostalCodeLookup;
use App\Modules\Shipping\PostalCode\PostalCodeNormalizer;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeLookupException;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/** GET /api/v1/postal-codes/{cep} (API.md §3.A) — used by the address form. */
final class PostalCodeController
{
    public function show(string $cep, PostalCodeLookup $lookup): JsonResponse
    {
        $postalCode = PostalCodeNormalizer::normalize($cep, 'cep');

        try {
            $info = $lookup->lookup($postalCode);
        } catch (PostalCodeNotFoundException) {
            throw new PostalCodeNotFound;
        } catch (PostalCodeLookupException $e) {
            Log::channel('shipping')->warning('shipping.postal_lookup.failed', ['cep_prefix' => substr($postalCode, 0, 5), 'reason' => mb_substr($e->getMessage(), 0, 40)]);
            throw new PostalCodeLookupUnavailable;
        }

        $source = $lookup instanceof CachedPostalCodeLookup ? ($lookup->lastSource() ?? 'viacep') : 'viacep';

        return new JsonResponse(['data' => [
            'postal_code' => $info->postalCode,
            'street' => $info->street,
            'district' => $info->district,
            'city' => $info->city,
            'state' => $info->state,
            'city_ibge_code' => $info->cityIbgeCode,
            'source' => $source,
        ]], 200, ['Cache-Control' => 'private, max-age=3600']);
    }
}
