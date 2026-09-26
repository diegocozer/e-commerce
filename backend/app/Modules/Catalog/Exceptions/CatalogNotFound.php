<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Shared\Exceptions\DomainException;

/** 404 not_found; product 404 carries `category` (API.md §3.A GET /products/{slug}). */
final class CatalogNotFound extends DomainException
{
    protected string $errorCode = 'not_found';

    protected int $httpStatus = 404;

    /** @param  array{id: int, name: string, slug: string, url_path: string}|null  $category */
    public static function product(?array $category): self
    {
        return new self('Produto não encontrado.', details: ['category' => $category]);
    }

    public static function category(): self
    {
        return new self('Categoria não encontrada.');
    }
}
