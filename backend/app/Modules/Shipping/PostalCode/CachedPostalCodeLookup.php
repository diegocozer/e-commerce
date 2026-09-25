<?php

declare(strict_types=1);

namespace App\Modules\Shipping\PostalCode;

use App\Shared\PostalCode\PostalCodeInfo;
use App\Shared\PostalCode\PostalCodeLookup;
use App\Shared\PostalCode\PostalCodeNotFoundException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Cache decorator (key shipping:cep:{cep}): success 30 days, "not found" 24 h,
 * failures are never cached (SHIPPING.md §4.8).
 */
final class CachedPostalCodeLookup implements PostalCodeLookup
{
    private const string NOT_FOUND = '__not_found__';

    private ?string $lastSource = null;

    public function __construct(
        private readonly PostalCodeLookup $inner,
        private readonly Cache $cache,
        private readonly int $cacheDays = 30,
        private readonly int $notFoundHours = 24,
    ) {}

    public function lookup(string $postalCode): PostalCodeInfo
    {
        $key = 'shipping:cep:'.$postalCode;
        $cached = $this->cache->get($key);

        if ($cached === self::NOT_FOUND) {
            $this->lastSource = 'cache';
            throw new PostalCodeNotFoundException('not_found');
        }
        if (is_array($cached)) {
            $this->lastSource = 'cache';

            return new PostalCodeInfo(...$cached);
        }

        try {
            $info = $this->inner->lookup($postalCode);
        } catch (PostalCodeNotFoundException $e) {
            $this->cache->put($key, self::NOT_FOUND, now()->addHours($this->notFoundHours));
            throw $e;
        }

        $this->cache->put($key, [
            'postalCode' => $info->postalCode, 'street' => $info->street, 'district' => $info->district,
            'city' => $info->city, 'state' => $info->state, 'cityIbgeCode' => $info->cityIbgeCode,
        ], now()->addDays($this->cacheDays));
        $this->lastSource = 'viacep';

        return $info;
    }

    /** 'cache' | 'viacep' for the last successful/not-found lookup (API.md PostalCodeInfo.source). */
    public function lastSource(): ?string
    {
        return $this->lastSource;
    }
}
