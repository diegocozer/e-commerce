<?php

declare(strict_types=1);

namespace App\Modules\Cart\Services;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Contracts\CatalogQuery;
use App\Modules\Catalog\Contracts\SaleQuantityResolver;
use App\Modules\Catalog\DTOs\BillableQuantity;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Inventory\Contracts\InventoryService;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\SaleUnit;
use Illuminate\Validation\ValidationException;

/**
 * Validation shared by the cart item mutations (RN-CAR-004/005/006): active
 * variant, quantity rules on the (summed) line, 50-line limit and soft stock check
 * by the variant total. Nothing is persisted here.
 */
final class CartItemGuard
{
    public const int MAX_LINES = 50;

    public function __construct(
        private readonly CatalogQuery $catalog,
        private readonly SaleQuantityResolver $quantities,
        private readonly InventoryService $inventory,
    ) {}

    /** Active variant or 422 errors.variant_id "Produto indisponível.". */
    public function activeVariant(int $variantId): VariantData
    {
        $variant = $this->catalog->variant($variantId);
        if ($variant === null || ! $variant->isSellable()) {
            throw ValidationException::withMessages(['variant_id' => ['Produto indisponível.']]);
        }

        return $variant;
    }

    /**
     * Fills the item shape from the request input (quantity or width/height/pieces).
     *
     * @param  array{quantity?: mixed, width_m?: mixed, height_m?: mixed, pieces?: mixed}  $input
     */
    public function fill(CartItem $item, VariantData $variant, array $input, bool $partial = false): void
    {
        if ($variant->saleUnit === SaleUnit::SquareMeter) {
            if (array_key_exists('quantity', $input) && $input['quantity'] !== null) {
                throw ValidationException::withMessages(['quantity' => ['Informe largura, altura e peças para este produto.']]);
            }
            $width = self::metersToMm($input['width_m'] ?? null);
            $height = self::metersToMm($input['height_m'] ?? null);
            if ($width === null && ! $partial) {
                $width = $variant->fixedWidthMm;
            }
            if (! $partial || $width !== null) {
                $item->width_mm = $width ?? throw ValidationException::withMessages(['width_m' => ['Informe a largura.']]);
            }
            if (! $partial || $height !== null) {
                $item->height_mm = $height ?? throw ValidationException::withMessages(['height_m' => ['Informe a altura.']]);
            }
            if (! $partial || array_key_exists('pieces', $input)) {
                $item->pieces = isset($input['pieces']) ? (int) $input['pieces'] : 1;
            }
            $item->quantity = null;

            return;
        }

        if (! isset($input['quantity'])) {
            throw ValidationException::withMessages(['quantity' => ['Informe a quantidade.']]);
        }
        if (isset($input['width_m']) || isset($input['height_m']) || isset($input['pieces'])) {
            throw ValidationException::withMessages(['quantity' => ['Este produto é vendido por quantidade.']]);
        }
        $item->quantity = Quantity::fromNumeric($input['quantity']);
        $item->width_mm = null;
        $item->height_mm = null;
        $item->pieces = null;
    }

    /** Quantity rules for one line; 422 in the API.md §1.6 format. */
    public function resolve(VariantData $variant, CartItem $item): BillableQuantity
    {
        // InvalidSaleQuantity renders itself as 422 {message, errors, details.suggestions}.
        return $this->quantities->resolve($variant, CartCalculator::inputOf($item));
    }

    public function assertLineLimit(Cart $cart, int $extraLines = 1): void
    {
        if ($cart->items()->count() + $extraLines > self::MAX_LINES) {
            throw ValidationException::withMessages(['variant_id' => ['Seu carrinho atingiu o limite de '.self::MAX_LINES.' itens.']]);
        }
    }

    /**
     * Soft stock check by the variant total (no lock — the reservation happens at
     * checkout). 409 insufficient_stock with StockIssue[].
     *
     * @param  list<CartItem>  $variantItems  all the lines of the variant, after the change
     */
    public function assertStock(VariantData $variant, array $variantItems): void
    {
        $total = Quantity::zero();
        foreach ($variantItems as $item) {
            $total = $total->add($this->resolve($variant, $item)->stock);
        }
        $available = $this->inventory->availability([$variant->id])[$variant->id] ?? Quantity::zero();
        if ($total->greaterThan($available)) {
            $avail = Quantity::max($available, Quantity::zero());
            $e = InsufficientStock::forItems([[
                'cart_item_id' => null,
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $variant->productName,
                'requested_quantity' => $total->toNumber(),
                'available_quantity' => $avail->toNumber(),
            ]]);

            throw new InsufficientStock(
                "Estoque insuficiente para {$variant->productName} — {$variant->name}. Disponível: {$avail->format()} {$variant->saleUnit->abbreviation()}.",
                details: ['items' => $e->items()],
            );
        }
    }

    /** "1.2" | 1.2 | "1,20" → 1200 mm; RN-QTD-034 (≤ 2 decimals) is enforced by the Form Request. */
    public static function metersToMm(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Quantity::fromNumeric(is_string($value) ? str_replace(',', '.', $value) : $value)->milli();
    }
}
