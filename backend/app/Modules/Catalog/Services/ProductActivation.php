<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Shared\Domain\SaleUnit;

/** RN-CAT-012 + SHIPPING §2.2: reasons a product cannot be activated. */
final class ProductActivation
{
    /** @return list<string> */
    public function issues(Product $product): array
    {
        $product->loadMissing(['variants', 'primaryCategory']);
        $issues = [];
        $active = $product->variants->filter(fn (ProductVariant $v) => $v->is_active && $v->deleted_at === null);

        if ($active->filter(fn (ProductVariant $v) => $v->price_cents > 0)->isEmpty()) {
            $issues[] = 'Cadastre ao menos uma variante ativa com preço maior que zero.';
        }
        if ($product->primaryCategory === null || ! $product->primaryCategory->is_active || $product->primaryCategory->deleted_at !== null) {
            $issues[] = 'A categoria principal precisa estar ativa.';
        }
        foreach ($active as $v) {
            if ($v->weight_grams <= 0 && $product->sale_unit !== SaleUnit::Kg) {
                $issues[] = "Informe o peso da variante {$v->sku}.";
            }
            $rolled = in_array($product->sale_unit, [SaleUnit::LinearMeter, SaleUnit::SquareMeter], true);
            $missing = $rolled
                ? ($v->package_width_cm === null || $v->package_height_cm === null)
                : ($v->package_length_cm === null || $v->package_width_cm === null || $v->package_height_cm === null);
            if ($missing) {
                $issues[] = "Informe as dimensões de embalagem da variante {$v->sku}.";
            }
        }
        if (! $product->min_quantity->isMultipleOf($product->quantity_step)
            || ($product->max_quantity !== null && ! $product->max_quantity->isMultipleOf($product->quantity_step))) {
            $issues[] = 'A quantidade mínima e a máxima devem ser múltiplas do passo.';
        }

        return $issues;
    }
}
