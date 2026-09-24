<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Pricing\Enums\PriceListKind;
use Database\Factories\Pricing\PriceListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Price list (retail, wholesale, reseller, custom — DATABASE.md §3.2.1).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PriceListKind $kind
 * @property int|null $discount_bp
 * @property bool $is_default
 * @property bool $is_active
 */
class PriceList extends Model
{
    /** @use HasFactory<PriceListFactory> */
    use HasFactory;

    protected $table = 'price_lists';

    protected $fillable = ['code', 'name', 'kind', 'discount_bp', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => PriceListKind::class,
            'discount_bp' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PriceTier, $this> */
    public function tiers(): HasMany
    {
        return $this->hasMany(PriceTier::class);
    }

    protected static function newFactory(): PriceListFactory
    {
        return PriceListFactory::new();
    }
}
