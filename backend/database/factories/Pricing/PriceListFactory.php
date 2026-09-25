<?php

declare(strict_types=1);

namespace Database\Factories\Pricing;

use App\Modules\Pricing\Enums\PriceListKind;
use App\Modules\Pricing\Models\PriceList;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceList> */
class PriceListFactory extends Factory
{
    protected $model = PriceList::class;

    public function definition(): array
    {
        return [
            'code' => 'custom_'.fake()->unique()->numerify('######'),
            'name' => 'Tabela '.fake()->word(),
            'kind' => PriceListKind::Custom,
            'discount_bp' => 500,
            'is_default' => false,
            'is_active' => true,
        ];
    }
}
