<?php

namespace Tests\Feature\Programs;

use App\Domain\Programs\ProgramStatus;
use App\Domain\Saas\EntitlementService;
use App\Models\OrganizationEntitlement;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** Permissions per route, entitlements, tenant isolation by id (HTTP). */
class ProgramsAccessTest extends ProgramsTestCase
{
    #[Test]
    public function every_route_asks_for_its_permission(): void
    {
        $program = $this->program();
        $level = $this->level($program);
        $client = $this->client();
        $enrollment = $this->admit($program, $client, $level);
        $p = ['program' => $program->id];

        // [method, route, parameters, who may call it]  (viewer = clinician, manager, none)
        $matrix = [
            ['get', 'app.programs.index', [], ['clinician' => 200, 'manager' => 200, 'none' => 403]],
            ['get', 'app.programs.create', [], ['clinician' => 403, 'manager' => 200, 'none' => 403]],
            ['post', 'app.programs.store', [], ['clinician' => 403, 'manager' => 302, 'none' => 403]],
            ['get', 'app.programs.show', $p, ['clinician' => 200, 'manager' => 200, 'none' => 403]],
            ['get', 'app.programs.edit', $p, ['clinician' => 403, 'manager' => 200, 'none' => 403]],
            ['put', 'app.programs.update', $p, ['clinician' => 403, 'manager' => 302, 'none' => 403]],
            ['post', 'app.programs.status', $p, ['clinician' => 403, 'manager' => 302, 'none' => 403]],
            ['post', 'app.programs.levels.store', $p, ['clinician' => 403, 'manager' => 302, 'none' => 403]],
            ['post', 'app.programs.staff.store', $p, ['clinician' => 403, 'manager' => 302, 'none' => 403]],
            ['post', 'app.programs.sessions.store', $p, ['clinician' => 403, 'manager' => 302, 'none' => 403]],
            ['get', 'app.programs.admit', [], ['clinician' => 200, 'manager' => 200, 'none' => 403]],
            ['post', 'app.programs.admit.store', [], ['clinician' => 302, 'manager' => 302, 'none' => 403]],
            ['get', 'app.programs.enrollments.show', $p + ['enrollment' => $enrollment->id], ['clinician' => 404, 'manager' => 200, 'none' => 403]],
            ['post', 'app.programs.enrollments.hold', $p + ['enrollment' => $enrollment->id], ['clinician' => 404, 'manager' => 302, 'none' => 403]],
        ];

        foreach ($matrix as [$method, $route, $parameters, $expected]) {
            foreach (['clinician' => $this->clinician, 'manager' => $this->manager, 'none' => $this->none] as $who => $member) {
                $status = $this->as($member)->{$method}($this->url($route, $parameters))->getStatusCode();
                // A refused write that fails validation answers 302 with errors: what matters is that 403/404 never appear for the allowed.
                $this->assertSame($expected[$who], $status, "{$method} {$route} as {$who}");
            }
        }

        $this->app['auth']->forgetGuards();
        $this->get($this->url('app.programs.index'))->assertRedirect();
    }

    #[Test]
    public function the_navigation_item_follows_programs_view(): void
    {
        $this->as($this->none)->get($this->url('app.dashboard'))->assertDontSee(route('app.programs.index', ['organization' => $this->a->organization->slug]), false);
        $this->as($this->clinician)->get($this->url('app.dashboard'))->assertSee(route('app.programs.index', ['organization' => $this->a->organization->slug]), false);
    }

    #[Test]
    public function the_module_needs_the_programs_entitlement_and_levels_and_sessions_their_own(): void
    {
        $program = $this->program();
        $service = app(EntitlementService::class);

        foreach ([['programs', 'app.programs.index', []], ['levels_of_care', 'app.programs.levels.store', ['program' => $program->id]], ['groups', 'app.programs.sessions.store', ['program' => $program->id]]] as [$feature, $route, $parameters]) {
            OrganizationEntitlement::query()->updateOrCreate(['organization_id' => $this->a->organization->id, 'feature_key' => $feature], ['enabled' => false, 'reason' => 'test']);
            $service->flush();
            $call = str_contains($route, 'store') ? 'post' : 'get';
            $this->as($this->admin)->{$call}($this->url($route, $parameters))->assertForbidden();
            OrganizationEntitlement::query()->where('feature_key', $feature)->delete();
            $service->flush();
        }

        $this->as($this->admin)->get($this->url('app.programs.index'))->assertOk();
    }

    #[Test]
    public function another_organizations_records_are_not_found_by_id(): void
    {
        $theirs = $this->program(in: $this->b);
        $theirLevel = $this->level($theirs, in: $this->b);
        $theirClient = $this->clientIn($this->b->organization);
        $theirEnrollment = $this->admit($theirs, $theirClient, $theirLevel, in: $this->b);
        $mine = $this->program();

        $this->as($this->admin);
        $this->get($this->url('app.programs.show', ['program' => $theirs->id]))->assertNotFound();
        $this->get($this->url('app.programs.edit', ['program' => $theirs->id]))->assertNotFound();
        $this->post($this->url('app.programs.status', ['program' => $theirs->id]), ['status' => 'on_hold'])->assertNotFound();
        $this->get($this->url('app.programs.enrollments.show', ['program' => $theirs->id, 'enrollment' => $theirEnrollment->id]))->assertNotFound();
        // Their enrollment under MY program: scoped binding and tenant scope both refuse it.
        $this->get($this->url('app.programs.enrollments.show', ['program' => $mine->id, 'enrollment' => $theirEnrollment->id]))->assertNotFound();
        $this->post($this->url('app.programs.admit.store'), ['program_id' => $theirs->id, 'client_id' => $theirClient->id])->assertNotFound();
        $this->post($this->url('app.programs.admit.store'), ['program_id' => $mine->id, 'client_id' => $theirClient->id])->assertNotFound();
        $this->get($this->url('app.programs.index'))->assertOk()->assertDontSee($theirs->name);
    }

    #[Test]
    public function an_enrollment_under_the_wrong_program_of_the_same_organization_is_not_found(): void
    {
        $one = $this->program();
        $two = $this->program();
        $enrollment = $this->admit($one, $this->client());

        $this->as($this->admin)->get($this->url('app.programs.enrollments.show', ['program' => $two->id, 'enrollment' => $enrollment->id]))->assertNotFound();
    }

    #[Test]
    public function the_overview_shows_the_comps_regions_and_hides_what_the_member_may_not_use(): void
    {
        $program = $this->program(['name' => 'Wellness Group']);
        $level = $this->level($program, 'Level II – Intensive Outpatient');
        $this->admit($program, $this->client(), $level);

        $this->as($this->clinician)->get($this->url('app.programs.index'))
            ->assertOk()->assertSee('Program Overview')->assertSee('Quick Actions')->assertSee('Upcoming Program Schedule')
            ->assertSee('Wellness Group')->assertSee('Level II – Intensive Outpatient')->assertSee('1 Participant')
            ->assertDontSee('Create Program');
        $this->as($this->manager)->get($this->url('app.programs.index'))->assertSee('Create Program');
    }

    #[Test]
    public function the_status_tabs_and_search_narrow_the_cards(): void
    {
        $this->program(['name' => 'Alpha Active']);
        $this->program(['name' => 'Beta Upcoming'], status: ProgramStatus::Upcoming);
        $this->program(['name' => 'Gamma Hold'], status: ProgramStatus::OnHold);

        $this->as($this->admin)->get($this->url('app.programs.index', ['status' => 'upcoming']))->assertSee('Beta Upcoming')->assertDontSee('Alpha Active');
        $this->get($this->url('app.programs.index', ['status' => 'on_hold']))->assertSee('Gamma Hold')->assertDontSee('Beta Upcoming');
        $this->get($this->url('app.programs.index', ['q' => 'alph']))->assertSee('Alpha Active')->assertDontSee('Gamma Hold');
        $this->get($this->url('app.programs.index', ['q' => '%']))->assertOk()->assertDontSee('Alpha Active');
        $this->get($this->url('app.programs.index', ['status' => 'nonsense']))->assertOk()->assertSee('Alpha Active');
    }

    #[Test]
    public function creating_a_program_through_the_form_validates_and_audits(): void
    {
        $this->as($this->manager)->post($this->url('app.programs.store'), ['name' => '', 'color' => 'pink', 'starts_on' => 'tomorrow'])
            ->assertSessionHasErrors(['name', 'color', 'starts_on']);

        $this->post($this->url('app.programs.store'), ['name' => 'New Program', 'color' => 'green', 'starts_on' => '2026-11-01', 'place' => 'online', 'description' => 'Hello'])
            ->assertRedirect();
        $row = DB::table('programs')->where('name', 'New Program')->first();
        $this->assertSame('upcoming', $row->status);
        $this->assertTrue((bool) $row->is_online);
        $this->assertCount(1, $this->auditEntries('program.created'));
    }

    #[Test]
    public function a_clinician_lists_only_participants_who_are_their_own_clients_while_the_counts_stay_aggregate(): void
    {
        $program = $this->program(['name' => 'Wellness Group']);
        $mine = $this->client(['first_name' => 'Mine', 'last_name' => 'Client', 'primary_clinician_membership_id' => $this->clinician->id]);
        $other = $this->client(['first_name' => 'Other', 'last_name' => 'Person']);
        $this->admit($program, $mine);
        $this->admit($program, $other);

        $this->as($this->clinician)->get($this->url('app.programs.show', ['program' => $program->id, 'tab' => 'participants']))
            ->assertOk()->assertSee('Mine Client')->assertDontSee('Other Person');
        $this->get($this->url('app.programs.index'))->assertSee('2 Participants');
        $this->as($this->manager)->get($this->url('app.programs.show', ['program' => $program->id, 'tab' => 'participants']))
            ->assertSee('Mine Client')->assertSee('Other Person');
    }

    #[Test]
    public function the_report_shortcut_appears_only_when_a_reports_screen_exists(): void
    {
        $this->program();

        $this->as($this->admin)->get($this->url('app.programs.index'))
            ->assertOk()->assertSee('Add Participant')->assertSee('View Program Calendar')->assertDontSee('Generate Report');
    }
}
