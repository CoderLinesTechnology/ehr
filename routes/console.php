<?php

use App\Domain\Identity\SyncPermissionCatalogue;
use App\Domain\Saas\SyncFeatureCatalogue;
use Illuminate\Support\Facades\Artisan;

// Run on every deploy (idempotent): php artisan catalogue:sync
Artisan::command('catalogue:sync', function (SyncPermissionCatalogue $permissions, SyncFeatureCatalogue $features) {
    $result = $permissions();
    $features();
    $this->info('Permission catalogue synced: '.count($result['created']).' new, '.count($result['removed']).' removed. Feature catalogue synced.');
})->purpose('Sync the permission and feature catalogues (and default plans on first run)');
