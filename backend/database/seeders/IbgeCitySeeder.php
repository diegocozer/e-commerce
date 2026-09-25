<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IBGE municipalities from database/data/ibge_cities.csv (DATABASE.md §3.8.2a).
 * The versioned CSV currently holds the cities used by the shipping seed plus
 * all state capitals; replace it with the full IBGE list (5,570 rows) when
 * available — the seeder upserts whatever the file contains.
 */
class IbgeCitySeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/ibge_cities.csv');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        fgetcsv($handle, escape: '\\'); // header
        $rows = [];
        while (($line = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($line) === 3) {
                $rows[] = ['ibge_code' => $line[0], 'name' => $line[1], 'state' => $line[2]];
            }
        }
        fclose($handle);

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ibge_cities')->upsert($chunk, ['ibge_code'], ['name', 'state']);
        }
    }
}
