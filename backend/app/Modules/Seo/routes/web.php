<?php

/*
|--------------------------------------------------------------------------
| Seo — sitemap.xml, robots.txt and product/category HTML shell (ADR-015)
|--------------------------------------------------------------------------
| Prefix: / · route names: "seo.*" · middleware group 'seo' (no session, throttle:seo).
| nginx forwards /{category} and /{category}/{product} (not files, not reserved) here.
*/

use App\Modules\Seo\Http\Controllers\RobotsController;
use App\Modules\Seo\Http\Controllers\ShellController;
use App\Modules\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', [SitemapController::class, 'show'])->name('sitemap');
Route::get('/robots.txt', [RobotsController::class, 'show'])->name('robots');

// Slug pattern excluding reserved storefront/infrastructure segments (ADR-015/026a).
$reserved = 'busca|carrinho|checkout|conta|entrar|cadastro|recuperar-senha|redefinir-senha|institucional|admin|api|sanctum|storage|up|health';
$slug = '(?!(?:'.$reserved.')(?![a-z0-9-]))[a-z0-9]+(?:-[a-z0-9]+)*';

Route::get('/{category}/{product}', [ShellController::class, 'show'])
    ->where(['category' => $slug, 'product' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->name('shell.product');
Route::get('/{category}', [ShellController::class, 'show'])->where('category', $slug)->name('shell.category');
