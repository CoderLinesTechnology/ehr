<?php

namespace Tests\Feature\Platform;

use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\Events\OrganizationStatusChanged;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Shared\DomainException;
use App\Models\Organization;
use App\Models\OrganizationStatusHistory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

class OrganizationLifecycleTest extends PlatformTestCase
{
    /** The lifecycle, written out independently of OrganizationStatus. */
    private const ALLOWED = [
        'pending' => ['trial', 'active', 'cancelled'],
        'trial' => ['active', 'suspended', 'cancelled'],
        'active' => ['suspended', 'cancelled'],
        'suspended' => ['active', 'trial', 'cancelled', 'archived'],
        'cancelled' => ['active', 'archived'],
        'archived' => ['active'],
    ];

    /** Moves that take access away: they need a reason. */
    private const RESTRICTIVE = ['suspended', 'archived', 'cancelled'];

    private function organizationIn(string $status): Organization
    {
        $organization = $this->createOrganization()->organization;
        $organization->forceFill(['status' => $status])->save();

        return $organization;
    }

    private function history(Organization $organization)
    {
        return OrganizationStatusHistory::query()->where('organization_id', $organization->id)->orderBy('occurred_at')->orderBy('id')->get();
    }

    #[Test]
    public function every_allowed_move_is_applied_with_history_audit_and_an_event(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        Event::fake([OrganizationStatusChanged::class]);
        $moves = 0;

        foreach (self::ALLOWED as $from => $targets) {
            foreach ($targets as $to) {
                $organization->forceFill(['status' => $from, 'status_reason' => null])->save();
                $before = $this->history($organization)->count();

                app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::from($to), $admin, "Moving {$from} to {$to}");
                $moves++;

                $fresh = $organization->fresh();
                $this->assertSame($to, $fresh->status->value, "{$from} to {$to}");
                $this->assertSame("Moving {$from} to {$to}", $fresh->status_reason);
                $this->assertNotNull($fresh->status_changed_at);
                $this->assertSame($to, $organization->status->value, "{$from} to {$to}: the caller's instance is brought up to date");

                $history = $this->history($organization);
                $this->assertCount($before + 1, $history);
                $row = $history->last();
                $this->assertSame($from, $row->from_status->value);
                $this->assertSame($to, $row->to_status->value);
                $this->assertSame("Moving {$from} to {$to}", $row->reason);
                $this->assertSame($admin->id, $row->actor_user_id);

                $audit = $this->audit('organization.status_changed');
                $this->assertSame('platform', $audit->context);
                $this->assertSame($organization->id, $audit->organization_id);
                $this->assertSame($admin->id, $audit->actor_user_id);
                $this->assertSame($from, $audit->before['status']);
                $this->assertSame($to, $audit->after['status']);
                $this->assertSame("Moving {$from} to {$to}", $audit->metadata['reason']);
            }
        }

        $this->assertSame(15, $moves, 'The lifecycle has fifteen allowed moves: 3 + 3 + 2 + 4 + 2 + 1.');
        Event::assertDispatchedTimes(OrganizationStatusChanged::class, 15);
    }

    #[Test]
    public function every_other_move_is_refused_with_a_message_and_leaves_nothing_behind(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        Event::fake([OrganizationStatusChanged::class]);
        $refused = 0;

        foreach (self::ALLOWED as $from => $targets) {
            foreach (array_keys(self::ALLOWED) as $to) {
                if ($to === $from || in_array($to, $targets, true)) {
                    continue;
                }
                $organization->forceFill(['status' => $from])->save();
                $historyBefore = $this->history($organization)->count();
                $auditBefore = $this->auditCount('organization.status_changed');

                try {
                    app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::from($to), $admin, 'Not allowed');
                    $this->fail("{$from} to {$to} must be refused.");
                } catch (DomainException $e) {
                    $this->assertSame('invalid_transition', $e->errorCode(), "{$from} to {$to}");
                    $this->assertSame(
                        'An organization that is '.OrganizationStatus::from($from)->label().' cannot be changed to '.OrganizationStatus::from($to)->label().'.',
                        $e->userMessage(),
                    );
                }
                $refused++;

                $this->assertSame($from, $organization->fresh()->status->value);
                $this->assertCount($historyBefore, $this->history($organization));
                $this->assertSame($auditBefore, $this->auditCount('organization.status_changed'));
            }
        }

        $this->assertSame(15, $refused, 'Six statuses, five targets each = 30 moves, fifteen of them allowed.');
        Event::assertNotDispatched(OrganizationStatusChanged::class);
    }

    #[Test]
    public function the_table_the_screen_uses_matches_the_specification(): void
    {
        foreach (self::ALLOWED as $from => $targets) {
            $status = OrganizationStatus::from($from);
            $this->assertEqualsCanonicalizing($targets, array_map(fn ($s) => $s->value, $status->allowedTransitions()), $from);
        }
        foreach (OrganizationStatus::cases() as $status) {
            $this->assertSame(in_array($status->value, self::RESTRICTIVE, true), $status->isRestrictive(), $status->value);
        }
        $this->assertTrue(OrganizationStatus::Trial->allowsAccess());
        $this->assertTrue(OrganizationStatus::Active->allowsAccess());
        foreach ([OrganizationStatus::Pending, OrganizationStatus::Suspended, OrganizationStatus::Archived, OrganizationStatus::Cancelled] as $status) {
            $this->assertFalse($status->allowsAccess(), $status->value);
        }
    }

    #[Test]
    public function taking_access_away_needs_a_reason_and_giving_it_back_does_not(): void
    {
        $admin = $this->signInAsPlatform();

        foreach (self::RESTRICTIVE as $to) {
            $organization = $this->organizationIn($to === 'cancelled' ? 'active' : ($to === 'archived' ? 'suspended' : 'active'));

            foreach ([null, '', '   '] as $blank) {
                try {
                    app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::from($to), $admin, $blank);
                    $this->fail("{$to} without a reason must be refused.");
                } catch (DomainException $e) {
                    $this->assertSame('reason_required', $e->errorCode(), $to);
                    $this->assertSame('reason', $e->field());
                }
            }
            $this->assertNotSame($to, $organization->fresh()->status->value, 'The refusal changed nothing.');
        }

        // Reactivating needs no reason (and keeps none when none is given).
        $suspended = $this->organizationIn('suspended');
        app(ChangeOrganizationStatus::class)($suspended, OrganizationStatus::Active, $admin);
        $this->assertSame(OrganizationStatus::Active, $suspended->fresh()->status);
        $this->assertNull($suspended->fresh()->status_reason);
        $this->assertTrue($suspended->fresh()->allowsAccess());
    }

    #[Test]
    public function asking_for_the_status_it_already_has_changes_nothing(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        $before = $this->history($organization)->count();

        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Active, $admin, 'Double click');

        $this->assertCount($before, $this->history($organization));
        $this->assertSame(0, $this->auditCount('organization.status_changed'));
    }

    #[Test]
    public function suspending_takes_access_away_at_once(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        $this->assertTrue($organization->allowsAccess());

        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, $admin, 'Unpaid invoice');

        $this->assertFalse($organization->allowsAccess(), 'The caller\'s instance shows it.');
        $this->assertFalse($organization->fresh()->allowsAccess());
    }

    #[Test]
    public function status_history_is_insert_only_in_the_model_and_in_the_database(): void
    {
        $admin = $this->signInAsPlatform();
        $organization = $this->createOrganization()->organization;
        app(ChangeOrganizationStatus::class)($organization, OrganizationStatus::Suspended, $admin, 'Unpaid invoice');
        $row = $this->history($organization)->last();

        foreach ([
            'update through the model' => fn () => $row->forceFill(['reason' => 'rewritten'])->save(),
            'delete through the model' => fn () => $row->delete(),
        ] as $name => $attempt) {
            try {
                $attempt();
                $this->fail("{$name} must be refused.");
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        // And around the model: the database trigger refuses too.
        foreach ([
            fn () => DB::table('organization_status_histories')->where('id', $row->id)->update(['reason' => 'rewritten']),
            fn () => DB::table('organization_status_histories')->where('id', $row->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database must refuse to change history.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('insert-only', $e->getMessage());
            }
        }

        $this->assertSame('Unpaid invoice', OrganizationStatusHistory::query()->findOrFail($row->id)->reason);
    }
}
