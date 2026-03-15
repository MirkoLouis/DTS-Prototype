<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Delegates to DevelopmentSeeder by default.
     */
    public function run(): void
    {
        $this->call(DevelopmentSeeder::class);
    }
}
