<?php

namespace Tests\Feature\Settings\Http;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class SettingsSectionsHttpTest extends SettingsHttpTestCase
{
    #[Test]
    public function another_organizations_records_are_not_found_by_id(): void
    {
        $foreignLocation = $this->location($this->other, 'Theirs');
        $foreignService = $this->service($this->other, 'Theirs');
        [$foreignInvite] = $this->invitation('x@example.com', $this->other);
        $foreignRole = $this->makeRole($this->other, 'Theirs', ['clients.view']);

        $this->asAdmin();
        $this->get($this->path("locations/{$foreignLocation->id}/edit"))->assertNotFound();
        $this->put($this->path("locations/{$foreignLocation->id}"), ['name' => 'Hacked', 'timezone' => 'Africa/Accra'])->assertNotFound();
        $this->get($this->path("services/{$foreignService->id}/edit"))->assertNotFound();
        $this->patch($this->path("services/{$foreignService->id}/status"), ['active' => 0])->assertNotFound();
        $this->get($this->path("team/{$this->otherAdmin->id}/edit"))->assertNotFound();
        $this->post($this->path("team/invitations/{$foreignInvite->id}/resend"))->assertNotFound();
        $this->delete($this->path("team/invitations/{$foreignInvite->id}"))->assertNotFound();
        $this->get($this->path("roles/{$foreignRole->id}/edit"))->assertNotFound();
        $this->delete($this->path("roles/{$foreignRole->id}"))->assertNotFound();

        $this->assertSame('Theirs', Location::query()->withoutGlobalScopes()->findOrFail($foreignLocation->id)->name);
    }

    #[Test]
    public function locations_are_added_edited_and_deactivated(): void
    {
        $this->asAdmin();
        $hours = [1 => ['closed' => '0', 'open' => '08:00', 'close' => '17:00']];
        $this->post($this->path('locations'), ['name' => 'Kumasi', 'timezone' => 'Africa/Accra', 'country_code' => 'GH', 'hours' => $hours])->assertRedirect($this->path('locations'));
        $location = Location::query()->where('name', 'Kumasi')->firstOrFail();

        $this->put($this->path("locations/{$location->id}"), ['name' => 'Kumasi Central', 'timezone' => 'Africa/Accra', 'hours' => $hours])->assertRedirect();
        $this->get($this->path('locations'))->assertOk()->assertSee('Kumasi Central');

        $this->patch($this->path("locations/{$location->id}/status"), ['active' => '0'])->assertRedirect();
        $this->assertFalse((bool) $location->fresh()->is_active);
        $this->patch($this->path("locations/{$location->id}/status"), ['active' => '1'])->assertRedirect();
        $this->assertTrue((bool) $location->fresh()->is_active);
    }

    #[Test]
    public function location_validation_errors_come_back_on_the_field(): void
    {
        $this->asAdmin()->from($this->path('locations/create'));
        $this->post($this->path('locations'), ['name' => '', 'timezone' => 'Nowhere/Land'])->assertSessionHasErrors(['name', 'timezone']);
        $this->location(null, 'Dup');
        $this->post($this->path('locations'), ['name' => 'dup', 'timezone' => 'Africa/Accra'])->assertSessionHasErrors('name');
        $this->post($this->path('locations'), ['name' => 'Late', 'timezone' => 'Africa/Accra', 'hours' => [1 => ['closed' => '0', 'open' => '17:00', 'close' => '08:00']]])->assertSessionHasErrors('hours.1.close');
    }

    #[Test]
    public function services_store_the_price_in_minor_units_and_show_it_formatted(): void
    {
        $this->asAdmin();
        $this->post($this->path('services'), ['name' => 'Therapy', 'duration_minutes' => 50, 'price' => '250.50', 'billing_behavior' => 'billable', 'allows_in_person' => '1'])->assertRedirect($this->path('services'));

        $service = Service::query()->where('name', 'Therapy')->firstOrFail();
        $this->assertSame(25050, $service->price_minor);
        $this->assertSame('GHS', $service->currency);
        $this->get($this->path('services'))->assertSee('Therapy')->assertSee('50 min');
        $this->get($this->path("services/{$service->id}/edit"))->assertOk()->assertSee('250.50');

        $this->patch($this->path("services/{$service->id}/status"), ['active' => '0'])->assertRedirect();
        $this->assertFalse((bool) $service->fresh()->is_active);
    }

    #[Test]
    public function service_rules_are_reported_per_field(): void
    {
        $this->asAdmin()->from($this->path('services/create'));
        $this->post($this->path('services'), ['name' => 'X', 'duration_minutes' => 50, 'price' => '12,5', 'billing_behavior' => 'billable', 'allows_in_person' => '1'])->assertSessionHasErrors('price');
        $this->post($this->path('services'), ['name' => 'X', 'duration_minutes' => 50, 'price' => '10', 'billing_behavior' => 'billable'])->assertSessionHasErrors('allows_in_person');
        $this->post($this->path('services'), ['name' => 'X', 'duration_minutes' => 2, 'price' => '10', 'billing_behavior' => 'billable', 'allows_in_person' => '1'])->assertSessionHasErrors('duration_minutes');
    }

    #[Test]
    public function the_team_list_invites_resends_and_revokes(): void
    {
        Notification::fake();
        $roleId = Role::query()->forOrganization($this->org->id)->where('key', 'staff')->value('id');
        $this->asAdmin();

        $this->post($this->path('team/invitations'), ['email' => 'Nina@Example.com', 'roles' => [$roleId], 'title' => 'Nurse'])->assertRedirect($this->path('team'));
        $invite = OrganizationMembership::query()->where('invited_email', 'nina@example.com')->firstOrFail();
        $this->get($this->path('team'))->assertOk()->assertSee('nina@example.com');

        $this->post($this->path("team/invitations/{$invite->id}/resend"))->assertRedirect();
        $this->delete($this->path("team/invitations/{$invite->id}"))->assertRedirect();
        $this->assertNull(OrganizationMembership::query()->find($invite->id)?->invitation_token_hash);

        $this->from($this->path('team'))->post($this->path('team/invitations'), ['email' => 'bad', 'roles' => []])->assertSessionHasErrors(['email', 'roles']);
        $this->post($this->path('team/invitations'), ['email' => 'a@example.com', 'roles' => ['not-a-uuid']])->assertSessionHasErrors('roles');
    }

    #[Test]
    public function a_member_gets_a_prefix_and_a_calendar_colour_from_the_families_only(): void
    {
        $member = $this->addStaff($this->org, 'clinician');
        $roleId = Role::query()->forOrganization($this->org->id)->where('key', 'clinician')->value('id');
        $this->asAdmin();

        $this->get($this->path("team/{$member->id}/edit"))->assertOk()->assertSee('Name prefix')->assertSee('Calendar colour');
        $this->put($this->path("team/{$member->id}"), ['name_prefix' => 'Dr.', 'title' => 'Psychologist', 'is_provider' => '1', 'color' => '#16b482', 'roles' => [$roleId]])->assertRedirect($this->path('team'));

        $fresh = OrganizationMembership::query()->findOrFail($member->id);
        $this->assertSame(['Dr.', '#16b482', true], [$fresh->name_prefix, $fresh->color, $fresh->is_provider]);

        $this->from($this->path("team/{$member->id}/edit"))->put($this->path("team/{$member->id}"), ['color' => '#123456', 'roles' => [$roleId]])->assertSessionHasErrors('color');
        $this->put($this->path("team/{$member->id}"), ['title' => 'x', 'roles' => []])->assertSessionHasErrors('roles');
    }

    #[Test]
    public function status_changes_record_the_reason_and_you_cannot_suspend_yourself(): void
    {
        $member = $this->addStaff($this->org, 'staff');
        $this->asAdmin();

        $this->patch($this->path("team/{$member->id}/status"), ['to' => 'suspended', 'reason' => 'On leave'])->assertRedirect();
        $this->assertSame('suspended', OrganizationMembership::query()->findOrFail($member->id)->status->value);
        $this->assertNotNull(AuditLog::query()->where('organization_id', $this->org->id)->where('action', 'like', 'team.%status%')->first() ?? AuditLog::query()->where('organization_id', $this->org->id)->where('action', 'like', 'team.%')->first());

        $this->patch($this->path("team/{$member->id}/status"), ['to' => 'active'])->assertRedirect();
        $this->patch($this->path("team/{$this->admin->id}/status"), ['to' => 'suspended'])->assertForbidden();
        $this->patch($this->path("team/{$member->id}/status"), ['to' => 'bogus'])->assertSessionHasErrors('to');
    }

    #[Test]
    public function roles_are_created_edited_and_deleted_and_the_locked_role_is_read_only(): void
    {
        $this->asAdmin();
        $this->post($this->path('roles'), ['name' => 'Front desk', 'description' => 'Reception', 'permissions' => ['clients.view', 'appointments.view']])->assertRedirect($this->path('roles'));
        $role = Role::query()->forOrganization($this->org->id)->where('name', 'Front desk')->firstOrFail();
        $this->assertSame(['appointments.view', 'clients.view'], $this->permissionsOfRole($role));

        $this->get($this->path('roles'))->assertSee('Front desk');
        $this->put($this->path("roles/{$role->id}"), ['name' => 'Front desk', 'permissions' => ['clients.view']])->assertRedirect();
        $this->assertSame(['clients.view'], $this->permissionsOfRole($role));

        $locked = Role::query()->forOrganization($this->org->id)->where('key', 'org_admin')->firstOrFail();
        $page = $this->get($this->path("roles/{$locked->id}/edit"))->assertOk()->assertSee('locked');
        $this->assertStringNotContainsString('Save role', $page->getContent());
        $this->put($this->path("roles/{$locked->id}"), ['name' => 'x', 'permissions' => []])->assertForbidden();
        $this->delete($this->path("roles/{$locked->id}"))->assertForbidden();

        $this->delete($this->path("roles/{$role->id}"))->assertRedirect($this->path('roles'));
        $this->assertNull(Role::query()->find($role->id));
    }

    #[Test]
    public function a_role_in_use_cannot_be_deleted_and_the_error_is_shown(): void
    {
        $role = $this->makeRole($this->org, 'Busy', ['clients.view']);
        $this->giveRole($this->addStaff($this->org, 'staff'), $role);

        $this->asAdmin()->from($this->path('roles'))->delete($this->path("roles/{$role->id}"))->assertSessionHasErrors();
        $this->assertNotNull(Role::query()->find($role->id));
    }

    #[Test]
    public function scheduling_and_client_settings_are_generated_from_the_registry_and_saved(): void
    {
        $this->asAdmin()->get($this->path('scheduling'))->assertOk()->assertSee('Minimum booking notice')->assertSee('Calendar shows from');
        $this->put($this->path('scheduling'), ['settings' => [
            'scheduling__default_duration_minutes' => '45', 'scheduling__slot_interval_minutes' => '30', 'scheduling__min_notice_hours' => '2',
            'scheduling__max_advance_days' => '90', 'scheduling__cancellation_notice_hours' => '12', 'scheduling__allow_overbooking' => '0',
            'scheduling__calendar_day_start' => '08:00', 'scheduling__calendar_day_end' => '18:00',
        ]])->assertRedirect($this->path('scheduling'))->assertSessionHas('success');
        $this->assertSame(45, app(\App\Domain\Settings\SettingsService::class)->organization($this->org, 'scheduling.default_duration_minutes'));

        $this->put($this->path('clients'), ['settings' => ['clients__require_date_of_birth' => '1', 'clients__require_contact' => '0']])->assertRedirect($this->path('clients'));
        $this->assertTrue(app(\App\Domain\Settings\SettingsService::class)->organization($this->org, 'clients.require_date_of_birth'));
    }

    #[Test]
    public function setting_validation_and_unknown_keys_are_refused(): void
    {
        $this->asAdmin()->from($this->path('scheduling'));
        $base = ['scheduling__default_duration_minutes' => '45', 'scheduling__slot_interval_minutes' => '30', 'scheduling__min_notice_hours' => '2',
            'scheduling__max_advance_days' => '90', 'scheduling__cancellation_notice_hours' => '12', 'scheduling__allow_overbooking' => '0',
            'scheduling__calendar_day_start' => '08:00', 'scheduling__calendar_day_end' => '18:00'];

        $this->put($this->path('scheduling'), ['settings' => ['scheduling__calendar_day_end' => '07:00'] + $base])->assertSessionHasErrors('settings.scheduling__calendar_day_end');
        $this->put($this->path('scheduling'), ['settings' => ['scheduling__min_notice_hours' => 'abc'] + $base])->assertSessionHasErrors('settings.scheduling__min_notice_hours');
        $this->put($this->path('scheduling'), ['settings' => ['clients__require_contact' => '0'] + $base])->assertSessionHasErrors('settings');
    }

    #[Test]
    public function the_audit_log_shows_this_organizations_entries_only_and_paginates_simply(): void
    {
        $this->actAs($this->otherAdmin, fn () => app(\App\Domain\Audit\AuditLogger::class)->record('organization.test_marker', $this->other, summary: 'Other tenant secret entry'));
        $this->asAdmin()->put($this->path('organization'), ['section' => 'profile', 'name' => 'Accra Renamed'])->assertRedirect();

        $this->get($this->path('audit'))->assertOk()->assertSee('Updated the organization profile')->assertDontSee('Other tenant secret entry');
        $this->get($this->path('audit').'?q=renamed-nothing-matches')->assertOk()->assertSee('Nothing to show');
        $this->get($this->path('audit').'?area='.urlencode("x'; drop table audit_logs;--"))->assertOk();
    }

    #[Test]
    public function the_subscription_page_shows_the_plan_and_usage(): void
    {
        $this->asAdmin()->get($this->path('subscription'))->assertOk()->assertSee('Usage')->assertSee('Staff')->assertSee('of');
    }
}
