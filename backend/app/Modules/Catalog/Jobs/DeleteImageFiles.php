<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Support\ImageUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;

final class DeleteImageFiles implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly string $disk, public readonly string $path)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $files = [$this->path];
        foreach (config('catalog.image_sizes', [300, 800, 1600]) as $size) {
            $files[] = ImageUrls::derivative($this->path, $size);
        }
        Storage::disk($this->disk)->delete($files);
    }
}
