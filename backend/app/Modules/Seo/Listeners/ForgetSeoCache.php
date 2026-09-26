<?php

declare(strict_types=1);

namespace App\Modules\Seo\Listeners;

use App\Modules\Seo\Services\SeoCache;
use Illuminate\Contracts\Queue\ShouldQueue;

/** ProductSaved / ProductDeleted / CategoryTreeChanged → sitemap and shell caches invalidated. */
final class ForgetSeoCache implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(object $event): void
    {
        SeoCache::flush();
    }
}
