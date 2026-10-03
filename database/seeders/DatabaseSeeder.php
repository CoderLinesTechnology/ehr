<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Catalogue data everywhere; sample people and organizations only in
     * local development (never mixed into a production database).
     */
    public function run(): void
    {
        $this->call(CatalogueSeeder::class);

        if (app()->isLocal()) {
            $this->call(DevelopmentSeeder::class);
        }
    }
}
