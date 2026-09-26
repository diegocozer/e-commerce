<?php

declare(strict_types=1);

namespace App\Modules\Seo\Http\Controllers;

use App\Modules\Catalog\Contracts\StorefrontSeo;
use App\Modules\Seo\Services\SeoCache;
use App\Modules\Seo\Services\ShellRenderer;
use App\Modules\Settings\Contracts\SettingsRepository;
use App\Modules\Settings\Enums\SettingKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * SEO shell (ADR-015 / API.md §3.A): 200 with meta for visible pages, 404 +
 * noindex for unknown slugs (same HTML), 301 to the canonical category path.
 */
final class ShellController
{
    public function __construct(
        private readonly StorefrontSeo $catalog,
        private readonly ShellRenderer $renderer,
        private readonly SettingsRepository $settings,
    ) {}

    public function show(string $category, ?string $product = null): Response|RedirectResponse
    {
        $path = '/'.$category.($product !== null ? '/'.$product : '');
        $cached = Cache::get(SeoCache::key('shell:'.$path));
        if (is_array($cached)) {
            return $this->respond($cached);
        }

        if ($product !== null) {
            $data = $this->catalog->product($product);
            $result = match (true) {
                $data === null => ['status' => 404, 'seo' => $this->notFound()],
                $data['primary_category_slug'] !== $category => ['status' => 301, 'location' => $data['seo']['canonical_url']],
                default => ['status' => 200, 'seo' => $data['seo']],
            };
        } else {
            $seo = $this->catalog->category($category);
            $result = $seo === null ? ['status' => 404, 'seo' => $this->notFound()] : ['status' => 200, 'seo' => $seo];
        }

        Cache::put(SeoCache::key('shell:'.$path), $result, now()->addMinutes((int) config('seo.shell_ttl_minutes', 15)));

        return $this->respond($result);
    }

    /** @param  array<string, mixed>  $result */
    private function respond(array $result): Response|RedirectResponse
    {
        if ($result['status'] === 301) {
            return new RedirectResponse($result['location'], 301);
        }

        return $this->renderer->render($result['seo'], $result['status']);
    }

    /** @return array<string, mixed> */
    private function notFound(): array
    {
        return [
            'title' => 'Página não encontrada | '.(string) $this->settings->get(SettingKey::StoreName),
            'description' => 'A página que você procura não existe ou não está mais disponível.',
            'robots' => 'noindex,follow',
            'canonical_url' => null,
            'og_image_url' => null,
            'json_ld' => [],
        ];
    }
}
