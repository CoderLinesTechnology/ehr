<?php

namespace Database\Seeders;

use App\Domain\Identity\SyncPermissionCatalogue;
use App\Domain\Saas\SyncFeatureCatalogue;
use Illuminate\Database\Seeder;

/**
 * Production-safe, idempotent: permission catalogue, platform roles, feature
 * catalogue and (first run only) the default plans. Contains no people and
 * no customer data.
 */
class CatalogueSeeder extends Seeder
{
    public function run(SyncPermissionCatalogue $permissions, SyncFeatureCatalogue $features): void
    {
        $permissions();
        $features();
    }
}
