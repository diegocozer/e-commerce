<?php

return [
    // Disk for product/category/brand images (ADR-002: s3/MinIO; dev uses `public`, tests fake it).
    'images_disk' => env('CATALOG_IMAGES_DISK', 'public'),
    'image_sizes' => [300, 800, 1600],
    'max_images_per_product' => 10,
    // Public storefront URL used for canonical links (APP_URL when null).
    'storefront_url' => env('STOREFRONT_URL'),
    'reserved_slugs' => [
        'busca', 'carrinho', 'checkout', 'conta', 'entrar', 'cadastro', 'recuperar-senha', 'redefinir-senha', 'verificar-email',
        'institucional', 'admin', 'api', 'sanctum', 'sitemap.xml', 'robots.txt',
    ],
];
