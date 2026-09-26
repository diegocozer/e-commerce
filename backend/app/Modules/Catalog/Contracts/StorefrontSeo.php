<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/**
 * SEO data of visible catalog pages for the Seo module (ADR-015): the same
 * `seo` block served by GET /products/{slug} and /categories/{slug}.
 */
interface StorefrontSeo
{
    /**
     * @return array{seo: array<string, mixed>, primary_category_slug: string}|null null when missing/inactive
     */
    public function product(string $productSlug): ?array;

    /** @return array<string, mixed>|null the Seo block, null when missing/inactive */
    public function category(string $categorySlug): ?array;

    /** @return list<array{path: string, lastmod: string|null}> active categories and products (canonical paths) */
    public function sitemapEntries(): array;
}
