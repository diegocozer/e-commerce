<?php

declare(strict_types=1);

namespace App\Modules\Payments\Gateways;

/**
 * Deterministic QR-looking PNG (base64, no "data:" prefix) for the sandbox PIX.
 * It is NOT a scannable QR code — the copy-paste payload is what matters in dev.
 */
final class FakeQrCode
{
    /** 1×1 transparent PNG used when GD is not available. */
    private const string FALLBACK = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public static function png(string $payload): string
    {
        if (! function_exists('imagecreate')) {
            return self::FALLBACK;
        }

        $modules = 25;
        $scale = 8;
        $size = ($modules + 2) * $scale;
        $image = imagecreate($size, $size);
        if ($image === false) {
            return self::FALLBACK;
        }
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        $bits = '';
        $seed = $payload;
        while (strlen($bits) < $modules * $modules) {
            $seed = hash('sha256', $seed, true);
            foreach (str_split($seed) as $byte) {
                $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
            }
        }

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                $finder = self::inFinder($x, $y, $modules);
                $on = $finder ?? ($bits[$y * $modules + $x] === '1');
                if ($on) {
                    imagefilledrectangle($image, ($x + 1) * $scale, ($y + 1) * $scale, ($x + 2) * $scale - 1, ($y + 2) * $scale - 1, $black);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($png);
    }

    /** Finder patterns in three corners (null = data module). */
    private static function inFinder(int $x, int $y, int $n): ?bool
    {
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$fx, $fy]) {
            if ($x >= $fx && $x < $fx + 7 && $y >= $fy && $y < $fy + 7) {
                $dx = $x - $fx;
                $dy = $y - $fy;
                $ring = min($dx, $dy, 6 - $dx, 6 - $dy);

                return $ring !== 1;
            }
        }

        return null;
    }
}
