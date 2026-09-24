<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Shared\Casts\QuantityCast;
use App\Shared\Domain\Quantity;
use Database\Factories\Pricing\PriceTierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantity price tier (DATABASE.md §3.2.2). `price_list_id` NULL = base price
 * tier. `variant_id` references Catalog (read via ProductVariant::priceTiers()).
 *
 * @property int $id
 * @property int $variant_id
 * @property int|null $price_list_id
 * @property Quantity $min_quantity
 * @property int $price_cents
 */
class PriceTier extends Model
{
    /** @use HasFactory<PriceTierFactory> */
    use HasFactory;

    protected $table = 'price_tiers';

    protected $fillable = ['variant_id', 'price_list_id', 'min_quantity', 'price_cents'];

    protected function casts(): array
    {
        return [
            'min_quantity' => QuantityCast::class,
            'price_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    protected static function newFactory(): PriceTierFactory
    {
        return PriceTierFactory::new();
    }
}
