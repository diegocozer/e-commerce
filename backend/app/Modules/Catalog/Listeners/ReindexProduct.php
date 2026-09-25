<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Catalog\Events\ProductSaved;
use Illuminate\Contracts\Queue\ShouldQueue;

/** ProductSaved → recompute products.search_vector (queue `default`, after commit). */
final class ReindexProduct implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly ProductSearch $search) {}

    public function handle(ProductSaved $event): void
    {
        $this->search->index($event->productId);
    }
}
