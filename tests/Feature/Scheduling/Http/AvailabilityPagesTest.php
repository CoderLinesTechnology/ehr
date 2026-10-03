<?php

namespace Tests\Feature\Scheduling\Http;

use App\Models\AvailabilityRule;
use App\Models\BlockedTime;
use PHPUnit\Framework\Attributes\Test;

class AvailabilityPagesTest extends SchedulingHttpTestCase
{
    private function rulePayload(array $extra = []): array
    {
        return $extra + ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '13:00', 'modality' => 'in_person', 'location_id' => $this->accra->id,
            'effective_from' => '2026-10-01', 'repeat_every_weeks' => 1, 'is_bookable_online' => 1, 'is_active' => 1];
    }

    #[Test]
    public function a_clinician_manages_only_their_own_availability(): void
    {
        $this->as($this->drA)->get($this->url('app.settings.availability.index', ['clinician' => $this->drB->id]))->assertOk()->assertDontSee($this->drB->professionalName());

        // A posted membership_id is ignored: the window lands on the clinician's own calendar.
        $this->post($this->url('app.settings.availability.rules.store'), $this->rulePayload(['membership_id' => $this->drB->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, AvailabilityRule::query()->where('membership_id', $this->drA->id)->count());
        $this->assertSame(0, AvailabilityRule::query()->where('membership_id', $this->drB->id)->count());

        $theirs = $this->rule($this->drB, $this->accra, 3, '09:00', '12:00');
        $mine = AvailabilityRule::query()->where('membership_id', $this->drA->id)->firstOrFail();

        $this->put($this->url('app.settings.availability.rules.update', ['rule' => $theirs->id]), $this->rulePayload())->assertNotFound();
        $this->delete($this->url('app.settings.availability.rules.destroy', ['rule' => $theirs->id]))->assertNotFound();
        $this->assertNotNull($theirs->fresh());

        $this->put($this->url('app.settings.availability.rules.update', ['rule' => $mine->id]), $this->rulePayload(['end_time' => '14:00', 'membership_id' => $this->drB->id]))->assertSessionHasNoErrors();
        $this->assertSame($this->drA->id, $mine->fresh()->membership_id);
        $this->assertStringStartsWith('14:00', (string) $mine->fresh()->end_time);

        $this->delete($this->url('app.settings.availability.rules.destroy', ['rule' => $mine->id]))->assertRedirect();
        $this->assertNull(AvailabilityRule::query()->find($mine->id));
    }

    #[Test]
    public function manage_all_chooses_the_clinician_and_may_block_the_whole_organization(): void
    {
        $receptionist = $this->addStaff($this->organization, 'receptionist');
        $this->as($receptionist)->get($this->url('app.settings.availability.index', ['clinician' => $this->drB->id]))->assertOk()->assertSee($this->drB->professionalName());

        $this->post($this->url('app.settings.availability.rules.store'), $this->rulePayload(['membership_id' => $this->drB->id]))->assertSessionHasNoErrors();
        $this->assertSame(1, AvailabilityRule::query()->where('membership_id', $this->drB->id)->count());

        $this->post($this->url('app.settings.availability.blocked.store'), ['scope' => 'everyone', 'kind' => 'holiday', 'title' => 'Independence Day', 'start_date' => '2026-10-10'])->assertSessionHasNoErrors();
        $this->assertTrue(BlockedTime::query()->whereNull('membership_id')->where('title', 'Independence Day')->where('all_day', true)->exists());
    }

    #[Test]
    public function organization_wide_blocks_need_manage_all(): void
    {
        $this->as($this->drA)->post($this->url('app.settings.availability.blocked.store'), ['scope' => 'everyone', 'kind' => 'holiday', 'start_date' => '2026-10-10'])
            ->assertSessionHasErrors('scope');
        $this->assertSame(0, BlockedTime::query()->count());

        // Their own leave, with a timed block, is fine; a posted other clinician is ignored.
        $this->post($this->url('app.settings.availability.blocked.store'), ['kind' => 'leave', 'start_date' => '2026-10-10', 'start_time' => '09:00', 'end_time' => '11:00', 'membership_id' => $this->drB->id])
            ->assertSessionHasNoErrors();
        $mine = BlockedTime::query()->firstOrFail();
        $this->assertSame($this->drA->id, $mine->membership_id);
        $this->assertFalse($mine->all_day);

        // An end before the start is refused on the end field.
        $this->post($this->url('app.settings.availability.blocked.store'), ['kind' => 'leave', 'start_date' => '2026-10-10', 'start_time' => '11:00', 'end_time' => '09:00'])
            ->assertSessionHasErrors('end_time');

        $everyone = $this->inTenant($this->organization, fn () => BlockedTime::factory()->create(['membership_id' => null]));
        $theirs = $this->inTenant($this->organization, fn () => BlockedTime::factory()->create(['membership_id' => $this->drB->id]));
        $this->delete($this->url('app.settings.availability.blocked.destroy', ['blocked' => $everyone->id]))->assertNotFound();
        $this->delete($this->url('app.settings.availability.blocked.destroy', ['blocked' => $theirs->id]))->assertNotFound();
        $this->delete($this->url('app.settings.availability.blocked.destroy', ['blocked' => $mine->id]))->assertRedirect();
        $this->assertNull(BlockedTime::query()->find($mine->id));
    }

    #[Test]
    public function another_organizations_rules_and_blocks_are_not_found(): void
    {
        [$rule, $block] = $this->inTenant($this->other, fn () => [
            AvailabilityRule::factory()->create(['membership_id' => $this->otherClinician->id, 'location_id' => null, 'modality' => 'telehealth']),
            BlockedTime::factory()->create(['membership_id' => null]),
        ]);

        $this->as($this->adminMembership)->put($this->url('app.settings.availability.rules.update', ['rule' => $rule->id]), $this->rulePayload())->assertNotFound();
        $this->delete($this->url('app.settings.availability.rules.destroy', ['rule' => $rule->id]))->assertNotFound();
        $this->delete($this->url('app.settings.availability.blocked.destroy', ['blocked' => $block->id]))->assertNotFound();
        $this->post($this->url('app.settings.availability.rules.store'), $this->rulePayload(['membership_id' => $this->otherClinician->id]))->assertSessionHasErrors('membership_id');
        $this->get($this->url('app.settings.availability.index', ['clinician' => $this->otherClinician->id]))->assertOk()->assertDontSee($this->otherClinician->professionalName());
    }

    #[Test]
    public function domain_refusals_land_on_the_form_field(): void
    {
        $this->as($this->adminMembership)->post($this->url('app.settings.availability.rules.store'), $this->rulePayload(['membership_id' => $this->drA->id, 'start_time' => '14:00', 'end_time' => '10:00']))
            ->assertSessionHasErrors('end_time');
        $this->post($this->url('app.settings.availability.rules.store'), $this->rulePayload(['membership_id' => $this->drA->id, 'location_id' => null]))
            ->assertSessionHasErrors('location_id');
    }
}
