<?php

declare(strict_types=1);

namespace App\Modules\Seo\Services;

use Illuminate\Http\Response;

/** Injects title/description/canonical/OG/JSON-LD into the storefront index.html shell. */
final class ShellRenderer
{
    public const string MARKER = '<!--seo:head-->';

    private const string FALLBACK = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        .self::MARKER.'</head><body><div id="root"></div></body></html>';

    /** @param  array<string, mixed>  $seo  API.md Seo block */
    public function render(array $seo, int $status): Response
    {
        $head = $this->head($seo);
        // The injected <title> replaces the static one of the SPA build.
        $html = (string) preg_replace('~<title>.*?</title>~is', '', $this->index(), 1);
        $html = str_contains($html, self::MARKER)
            ? str_replace(self::MARKER, $head, $html)
            : (string) preg_replace('~</head>~i', $head.'</head>', $html, 1);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Robots-Tag' => $seo['robots'] ?? 'index,follow',
        ]);
    }

    /** @param  array<string, mixed>  $seo */
    public function head(array $seo): string
    {
        $e = static fn ($v): string => e((string) $v);
        $parts = [
            '<title>'.$e($seo['title'] ?? '').'</title>',
            '<meta name="description" content="'.$e($seo['description'] ?? '').'">',
            '<meta name="robots" content="'.$e($seo['robots'] ?? 'index,follow').'">',
        ];
        if (! empty($seo['canonical_url'])) {
            $parts[] = '<link rel="canonical" href="'.$e($seo['canonical_url']).'">';
            $parts[] = '<meta property="og:url" content="'.$e($seo['canonical_url']).'">';
        }
        $parts[] = '<meta property="og:title" content="'.$e($seo['title'] ?? '').'">';
        $parts[] = '<meta property="og:description" content="'.$e($seo['description'] ?? '').'">';
        $parts[] = '<meta property="og:type" content="website">';
        if (! empty($seo['og_image_url'])) {
            $parts[] = '<meta property="og:image" content="'.$e($seo['og_image_url']).'">';
        }
        foreach ($seo['json_ld'] ?? [] as $ld) {
            $parts[] = '<script type="application/ld+json">'
                .json_encode($ld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                .'</script>';
        }

        return implode("\n", $parts);
    }

    private function index(): string
    {
        $path = (string) config('seo.shell_index_path');
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $html = file_get_contents($path);
            if (is_string($html) && $html !== '') {
                return $html;
            }
        }

        return self::FALLBACK;
    }
}
