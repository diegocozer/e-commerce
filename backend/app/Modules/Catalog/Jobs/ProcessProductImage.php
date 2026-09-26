<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ImageUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Generates WebP 300/800/1600 (longest side, no upscaling) and records the final size. */
final class ProcessProductImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $imageId)
    {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $image = ProductImage::query()->find($this->imageId);
        if ($image === null) {
            return;
        }
        $disk = Storage::disk($image->disk);
        $source = @imagecreatefromstring((string) $disk->get($image->path));
        if ($source === false) {
            throw new RuntimeException("Image {$image->id} could not be decoded.");
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $finalW = $w;
        $finalH = $h;
        foreach (config('catalog.image_sizes', [300, 800, 1600]) as $size) {
            $scale = min(1, $size / max($w, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            imagewebp($dst, null, 82);
            $disk->put(ImageUrls::derivative($image->path, $size), (string) ob_get_clean(), ['visibility' => 'public']);
            imagedestroy($dst);
            [$finalW, $finalH] = [$nw, $nh];
        }
        imagedestroy($source);

        $image->width_px = $finalW;
        $image->height_px = $finalH;
        $image->save();
    }
}
