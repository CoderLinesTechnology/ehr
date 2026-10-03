<?php

namespace Tests\Feature\Foundation;

use App\Domain\Tenancy\MissingTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenancyFoundationTest extends TestCase
{
    #[Test]
    public function tenant_models_refuse_to_query_without_a_tenant(): void
    {
        $this->expectException(MissingTenantContext::class);

        Client::query()->count();
    }

    #[Test]
    public function tenant_models_refuse_to_be_created_without_a_tenant(): void
    {
        $organization = $this->createOrganization()->organization;

        $this->expectException(MissingTenantContext::class);

        $location = new Location(['name' => 'Nowhere', 'timezone' => 'UTC']);
        $location->organization_id = $organization->id;
        $location->save();
    }

    #[Test]
    public function queries_only_see_the_current_organization(): void
    {
        $a = $this->createOrganization()->organization;
        $b = $this->createOrganization()->organization;

        $this->inTenant($a, fn () => Client::factory()->count(2)->create());
        $this->inTenant($b, fn () => Client::factory()->count(3)->create());

        $this->assertSame(2, $this->inTenant($a, fn () => Client::query()->count()));
        $this->assertSame(3, $this->inTenant($b, fn () => Client::query()->count()));
        $this->assertSame(5, app(TenantContext::class)->bypass(fn () => Client::query()->count()));
    }

    #[Test]
    public function a_row_cannot_be_created_for_another_organization_from_inside_a_tenant(): void
    {
        $a = $this->createOrganization()->organization;
        $b = $this->createOrganization()->organization;

        $this->expectException(TenantMismatch::class);

        $this->inTenant($a, function () use ($b) {
            $location = new Location(['name' => 'Sneaky', 'timezone' => 'UTC']);
            $location->organization_id = $b->id;
            $location->save();
        });
    }

    #[Test]
    public function the_database_rejects_cross_tenant_references_even_when_the_application_is_bypassed(): void
    {
        $a = $this->createOrganization()->organization;
        $b = $this->createOrganization()->organization;
        $locationInB = $this->inTenant($b, fn () => Location::factory()->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/');

        // A client in A pointing at a location in B: the composite FK refuses it.
        $this->inTenant($a, fn () => Client::factory()->create(['primary_location_id' => $locationInB->id]));
    }

    #[Test]
    public function organization_id_is_immutable(): void
    {
        $a = $this->createOrganization()->organization;
        $b = $this->createOrganization()->organization;

        $this->expectException(TenantMismatch::class);

        $this->inTenant($a, function () use ($b) {
            $service = Service::factory()->create();
            $service->organization_id = $b->id;
            $service->save();
        });
    }

    #[Test]
    public function route_bindings_resolve_only_inside_the_current_organization(): void
    {
        $a = $this->createOrganization();
        $b = $this->createOrganization();
        $clientInB = $this->inTenant($b->organization, fn () => Client::factory()->create());

        $resolved = $this->inTenant($a->organization, fn () => (new Client)->resolveRouteBinding($clientInB->id));

        $this->assertNull($resolved);
    }

    #[Test]
    public function memberships_of_another_organization_are_invisible(): void
    {
        $a = $this->createOrganization();
        $b = $this->createOrganization();

        $ids = $this->inTenant($a->organization, fn () => OrganizationMembership::query()->pluck('id')->all());

        $this->assertContains($a->ownerMembership->id, $ids);
        $this->assertNotContains($b->ownerMembership->id, $ids);
    }

    #[Test]
    public function demo_and_live_records_cannot_be_mixed_by_foreign_key(): void
    {
        $organization = $this->createOrganization()->organization;

        $this->expectException(QueryException::class);

        $this->inTenant($organization, function () {
            $client = Client::factory()->demo()->create();
            DB::table('timeline_entries')->insert([
                'id' => (string) str()->uuid7(),
                'organization_id' => $client->organization_id,
                'client_id' => $client->id,
                'record_environment' => 'live', // demo client, live entry → rejected
                'occurred_at' => now(),
                'category' => 'administrative',
                'type' => 'test',
                'summary' => 'x',
            ]);
        });
    }
}
