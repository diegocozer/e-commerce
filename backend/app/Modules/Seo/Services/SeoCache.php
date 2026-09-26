<?php

declare(strict_types=1);

namespace App\Modules\Seo\Services;

use Illuminate\Support\Facades\Cache;

/** Versioned cache keys: bumping the version invalidates every sitemap/shell entry at once. */
final class SeoCache
{
    public static function key(string $suffix): string
    {
        return 'seo:v'.Cache::get('seo:version', 1).':'.$suffix;
    }

    public static function flush(): void
    {
        Cache::forever('seo:version', ((int) Cache::get('seo:version', 1)) + 1);
    }
}
