<?php

namespace Tests\Unit\Scheduling;

use App\Domain\Scheduling\AppointmentStatus as S;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    #[Test]
    public function the_machine_allows_exactly_these_moves(): void
    {
        $allowed = [];
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    $allowed[] = "{$from->value}>{$to->value}";
                }
            }
        }

        $this->assertSame([
            'scheduled>confirmed', 'scheduled>checked_in', 'scheduled>in_progress', 'scheduled>completed', 'scheduled>cancelled', 'scheduled>no_show',
            'confirmed>checked_in', 'confirmed>in_progress', 'confirmed>completed', 'confirmed>cancelled', 'confirmed>no_show',
            'checked_in>in_progress', 'checked_in>completed', 'checked_in>cancelled',
            'in_progress>completed',
        ], $allowed);
    }

    #[Test]
    public function terminal_statuses_are_final_and_only_upcoming_ones_can_be_rescheduled(): void
    {
        foreach (S::cases() as $status) {
            $this->assertSame($status->allowedTransitions() === [], $status->isTerminal(), $status->value);
            $this->assertSame(in_array($status, [S::Scheduled, S::Confirmed], true), $status->canReschedule(), $status->value);
        }

        $this->assertSame(['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed'], S::OCCUPYING);
        $this->assertFalse(S::Cancelled->isOccupying());
        $this->assertTrue(S::Completed->isOccupying());
    }

    #[Test]
    public function the_clock_decides_when_a_status_may_be_entered(): void
    {
        $start = CarbonImmutable::parse('2026-10-06 14:00:00', 'UTC');

        $this->assertFalse(S::Completed->canEnterAt($start, $start->subSecond()));
        $this->assertTrue(S::Completed->canEnterAt($start, $start));
        $this->assertFalse(S::NoShow->canEnterAt($start, $start->subMinute()));
        $this->assertTrue(S::CheckedIn->canEnterAt($start, $start->subHours(2)));
        $this->assertFalse(S::CheckedIn->canEnterAt($start, $start->subHours(2)->subSecond()));
        $this->assertTrue(S::InProgress->canEnterAt($start, $start->subHours(2)));
        $this->assertTrue(S::Cancelled->canEnterAt($start, $start->subYear()));
        $this->assertTrue(S::Confirmed->canEnterAt($start, $start->subYear()));
    }

    #[Test]
    public function each_milestone_has_its_column(): void
    {
        $this->assertSame(
            ['scheduled' => null, 'confirmed' => 'confirmed_at', 'checked_in' => 'checked_in_at', 'in_progress' => 'started_at',
                'completed' => 'completed_at', 'cancelled' => 'cancelled_at', 'no_show' => 'no_show_at', 'rescheduled' => null],
            array_combine(S::values(), array_map(fn (S $s) => $s->timestampColumn(), S::cases())),
        );
    }
}
