<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /**
     * Run the database seeds for production.
     * This excludes the DocumentSeeder which generates dummy documents and metrics.
     */
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            PredictionKeywordSeeder::class,
            PurposeSeeder::class,
            UserSeeder::class,
        ]);
    }
}
