<?php

namespace Tests;

use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\InteractsWithTenancy;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTenancy, RefreshDatabase;

    /** Permission/feature catalogue and default plans, seeded once per run. */
    protected bool $seed = true;

    protected string $seeder = CatalogueSeeder::class;

    /**
     * Each HTTP call in a test is a fresh request: drop request/job-scoped
     * state (TenantContext, PermissionResolver, SettingsService …) left by the
     * previous call, as a real server would. Without this, a second request in
     * the same test can pass because of the first request's tenant.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
