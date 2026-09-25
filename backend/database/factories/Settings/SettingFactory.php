<?php

declare(strict_types=1);

namespace Database\Factories\Settings;

use App\Modules\Settings\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Setting> */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->lexify('????????'),
            'value' => fake()->word(),
            'group' => 'general',
            'is_public' => false,
            'description' => null,
        ];
    }
}
