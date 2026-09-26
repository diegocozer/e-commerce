<?php

declare(strict_types=1);

namespace App\Modules\Seo\Http\Controllers;

use Illuminate\Http\Response;

final class RobotsController
{
    public function show(): Response
    {
        $base = rtrim((string) (config('catalog.storefront_url') ?? config('app.url')), '/');
        if (app()->environment('staging')) {
            $body = "User-agent: *\nDisallow: /\n";
        } else {
            $lines = ['User-agent: *'];
            foreach (['/carrinho', '/checkout', '/conta', '/entrar', '/cadastro', '/recuperar-senha', '/redefinir-senha', '/verificar-email', '/admin', '/api/'] as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: '.$base.'/sitemap.xml';
            $body = implode("\n", $lines)."\n";
        }

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
