<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seed spec of DATABASE.md §7. All seeders are idempotent (updateOrCreate by
 * natural key). In production only reference data is seeded (no demo catalog,
 * no admin with a default password — create it with an artisan command).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RolesAndPermissionsSeeder::class,
            PriceListSeeder::class,
            IbgeCitySeeder::class,
            ShippingSeeder::class,
        ]);

        if (app()->isProduction()) {
            return;
        }

        $this->call([
            AdminUserSeeder::class,
            CatalogSeeder::class,
            // Customers before Pricing: customer_prices references the PJ company.
            CustomerSeeder::class,
            PricingSeeder::class,
            PromotionSeeder::class,
        ]);
    }
}
