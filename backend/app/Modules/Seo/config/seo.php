<?php

return [
    // Built storefront index.html; its <!--seo:head--> marker receives the meta tags (ADR-015).
    'shell_index_path' => env('SEO_SHELL_INDEX_PATH', base_path('../storefront/dist/index.html')),
    'sitemap_ttl_minutes' => 360,
    'shell_ttl_minutes' => 15,
];
