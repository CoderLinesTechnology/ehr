<?php

namespace Tests\Feature\Programs;

use App\Domain\Identity\PermissionResolver;
use App\Domain\Identity\SyncPermissionCatalogue;
use App\Domain\Platform\CreatedOrganization;
use App\Domain\Programs\AdmitClient;
use App\Domain\Programs\ChangeProgramStatus;
use App\Domain\Programs\ProgramStatus;
use App\Domain\Programs\SaveLevelOfCare;
use App\Domain\Programs\SaveProgram;
use App\Models\Client;
use App\Models\LevelOfCare;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramEnrollment;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Clients\ClientsTestCase;

/**
 * Two organizations (A, B) and the people the Programs rules distinguish, all through real roles:
 *   admin       Organization Administrator (everything, including programs.view_sud)
 *   manager     Practice Manager: programs.view/manage/enroll, every client, NO view_sud
 *   clinician   Clinician: programs.view/enroll, only their own clients
 *   supervisor  Clinical Supervisor: like a clinician but every client; granted programs.view_sud explicitly in setUp
 *               (no default role holds that sensitive permission: an organization grants it on purpose)
 *   none        Staff: no programs permission at all
 */
abstract class ProgramsTestCase extends ClientsTestCase
{
    protected CreatedOrganization $a;

    protected CreatedOrganization $b;

    protected OrganizationMembership $admin;

    protected OrganizationMembership $manager;

    protected OrganizationMembership $clinician;

    protected OrganizationMembership $supervisor;

    protected OrganizationMembership $none;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));

        $this->a = $this->createOrganization(['name' => 'Alpha Practice']);
        $this->b = $this->createOrganization(['name' => 'Beta Practice']);
        $this->admin = $this->a->ownerMembership;
        $this->manager = $this->addStaff($this->a->organization, 'practice_manager');
        $this->clinician = $this->addStaff($this->a->organization, 'clinician');
        $this->supervisor = $this->addStaff($this->a->organization, 'supervisor');
        $this->none = $this->addStaff($this->a->organization, 'staff');

        SyncPermissionCatalogue::grant(Role::query()->forOrganization($this->a->organization->id)->where('key', 'supervisor')->firstOrFail(), ['programs.view_sud']);
        app(PermissionResolver::class)->flush();
    }

    protected function as(OrganizationMembership $member): static
    {
        $this->app->forgetScopedInstances();
        $this->actingAs(User::query()->findOrFail($member->user_id));

        return $this;
    }

    protected function url(string $name, array $parameters = [], ?CreatedOrganization $in = null): string
    {
        return route($name, ['organization' => ($in ?? $this->a)->organization->slug] + $parameters);
    }

    /** Run a domain action as $member of $in (default: the administrator of A). */
    protected function doAs(\Closure $callback, ?OrganizationMembership $member = null, ?CreatedOrganization $in = null): mixed
    {
        $in ??= $this->a;
        $result = $this->actAs($member ?? $in->ownerMembership, $in->organization, $callback);
        $this->app['auth']->forgetGuards();

        return $result;
    }

    /** @param array<string, mixed> $input */
    protected function program(array $input = [], bool $sud = false, ProgramStatus $status = ProgramStatus::Active, ?CreatedOrganization $in = null): Program
    {
        $input += ['name' => 'Program '.fake()->unique()->numerify('####'), 'description' => 'A program.', 'color' => 'blue', 'starts_on' => '2026-01-01', 'is_sud_program' => $sud];

        return $this->doAs(function () use ($input, $status) {
            $program = app(SaveProgram::class)($input);
            if ($status !== ProgramStatus::Upcoming) {
                app(ChangeProgramStatus::class)($program, ProgramStatus::Active);
                if ($status !== ProgramStatus::Active) {
                    app(ChangeProgramStatus::class)($program, $status);
                }
            }

            return $program->fresh();
        }, in: $in);
    }

    protected function level(Program $program, string $name = 'Level I – Outpatient', ?CreatedOrganization $in = null, int $sort = 1): LevelOfCare
    {
        return $this->doAs(fn () => app(SaveLevelOfCare::class)($program, ['name' => $name, 'sort' => $sort]), in: $in);
    }

    /** Admit as the administrator (or $by). */
    protected function admit(Program $program, Client $client, ?LevelOfCare $level = null, ?OrganizationMembership $by = null, ?CreatedOrganization $in = null): ProgramEnrollment
    {
        return $this->doAs(fn () => app(AdmitClient::class)($program, $client, $level), $by, $in);
    }

    protected function client(array $attributes = [], bool $demo = false): Client
    {
        return $demo ? $this->demoClientIn($this->a->organization, $attributes) : $this->clientIn($this->a->organization, $attributes);
    }
}
