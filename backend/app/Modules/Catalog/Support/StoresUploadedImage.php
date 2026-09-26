<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

final class StoresUploadedImage
{
    /** File validation shared by category/brand/product uploads (API.md §3.G.3/§3.G.5). */
    public const array RULES = ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'];

    /** Stores with a server-generated name; returns the storage key. */
    public static function store(UploadedFile $file, string $directory): string
    {
        $ext = match ($file->getMimeType()) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };

        return $file->storeAs($directory, Str::uuid()->toString().'.'.$ext, ['disk' => config('catalog.images_disk')]);
    }
}
