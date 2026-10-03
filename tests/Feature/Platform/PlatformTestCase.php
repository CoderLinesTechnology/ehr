<?php

namespace Tests\Feature\Platform;

use App\Domain\Identity\RoleTemplates;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared helpers for the Super Admin console's domain tests. The tests call
 * the actions and read services directly: they are the layer that owns the
 * rules, and HTTP adapters are tested where they are built.
 */
abstract class PlatformTestCase extends TestCase
{
    /**
     * A platform user (confirmed two-factor) signed in as the current user, so
     * audit rows record them as the actor. Scoped services are reset first:
     * they hold per-request state (resolved permissions, the audit logger's
     * request) that must not leak from test setup into the code under test.
     */
    protected function signInAsPlatform(string $roleKey = RoleTemplates::SUPER_ADMIN, bool $withTwoFactor = true): User
    {
        $user = $this->platformUser($roleKey, $withTwoFactor)->fresh();
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return $user;
    }

    /** The newest audit row for an action, or a failure naming the action. */
    protected function audit(string $action): AuditLog
    {
        $row = AuditLog::query()->where('action', $action)->orderByDesc('occurred_at')->orderByDesc('id')->first();
        $this->assertNotNull($row, "No audit row was written for [{$action}].");

        return $row;
    }

    protected function auditCount(string $action): int
    {
        return AuditLog::query()->where('action', $action)->count();
    }

    /**
     * Run $callback and return [result, number of SQL statements it issued].
     *
     * @return array{0: mixed, 1: int}
     */
    protected function countQueries(Closure $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $callback();
        } finally {
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return [$result, $count];
    }

    /**
     * Prove a row is locked by this test's transaction: a second database
     * session cannot take it (FOR UPDATE NOWAIT fails at once). Row locks taken
     * inside a nested transaction stay held by the outer one, which is how the
     * test sees a lock the action took. Only COMMITTED rows are visible to the
     * second session (the seeded catalogue: plans, features, roles).
     */
    protected function assertRowIsLocked(string $table, string $column, string $value): void
    {
        config(['database.connections.lock_probe' => config('database.connections.'.config('database.default'))]);
        $probe = DB::connection('lock_probe');

        try {
            $visible = $probe->selectOne("select count(*) as n from {$table} where {$column} = ?", [$value]);
            $this->assertSame(1, (int) $visible->n, "The probe session cannot see the {$table} row: only committed rows can be checked for locks.");

            try {
                $probe->select("select 1 from {$table} where {$column} = ? for update nowait", [$value]);
                $this->fail("Expected the {$table} row to be locked, but a second session could lock it.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('could not obtain lock', $e->getMessage());
            }
        } finally {
            DB::purge('lock_probe');
        }
    }

    /** A client inside an organization (live unless asked). */
    protected function makeClient(Organization $organization, array $attributes = [], bool $demo = false): Client
    {
        return $this->inTenant($organization, fn () => ($demo ? Client::factory()->demo() : Client::factory())->create($attributes));
    }

    /**
     * An appointment for a client, starting at the given instant. Every one
     * borrows the organization's owner as clinician, so give each its own time
     * (a clinician cannot be double-booked).
     */
    protected function makeAppointment(Organization $organization, Client $client, CarbonImmutable $startsAt): Appointment
    {
        return $this->inTenant($organization, function () use ($client, $startsAt) {
            $clinician = OrganizationMembership::query()->where('status', 'active')->orderBy('created_at')->firstOrFail();

            return Appointment::factory()->at($startsAt)->create([
                'client_id' => $client->id,
                'clinician_membership_id' => $clinician->id,
                'service_id' => Service::query()->first()?->id ?? Service::factory()->create()->id,
                'location_id' => Location::query()->first()?->id ?? Location::factory()->create()->id,
            ]);
        });
    }
}
