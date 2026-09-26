<?php

declare(strict_types=1);

namespace App\Modules\Seo\Http\Controllers;

use App\Modules\Catalog\Contracts\StorefrontSeo;
use App\Modules\Seo\Services\SeoCache;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

final class SitemapController
{
    public function show(StorefrontSeo $catalog): Response
    {
        $xml = Cache::remember(SeoCache::key('sitemap'), now()->addMinutes((int) config('seo.sitemap_ttl_minutes', 360)), function () use ($catalog): string {
            $base = rtrim((string) (config('catalog.storefront_url') ?? config('app.url')), '/');
            $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
            $lines[] = '  <url><loc>'.e($base.'/').'</loc></url>';
            foreach ($catalog->sitemapEntries() as $entry) {
                $lines[] = '  <url><loc>'.e($base.$entry['path']).'</loc>'.($entry['lastmod'] !== null ? '<lastmod>'.e($entry['lastmod']).'</lastmod>' : '').'</url>';
            }
            $lines[] = '</urlset>';

            return implode("\n", $lines);
        });

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
