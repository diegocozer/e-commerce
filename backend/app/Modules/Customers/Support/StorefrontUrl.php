<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

/** Links sent by e-mail point to the storefront SPA (API.md §3.C). */
final class StorefrontUrl
{
    /** @param  array<string, string|int>  $query */
    public static function to(string $path, array $query = []): string
    {
        $base = rtrim((string) (config('app.storefront_url') ?? config('app.url')), '/');

        return $base.'/'.ltrim($path, '/').($query === [] ? '' : '?'.http_build_query($query));
    }
}
