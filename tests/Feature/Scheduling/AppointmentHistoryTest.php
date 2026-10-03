<?php

namespace Tests\Feature\Scheduling;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\CancellationKind;
use App\Domain\Scheduling\TransitionAppointment;
use App\Models\Appointment;
use App\Models\AppointmentStatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

class AppointmentHistoryTest extends SchedulingTestCase
{
    private AppointmentStatusHistory $history;

    protected function setUp(): void
    {
        parent::setUp();

        $appointment = Appointment::factory()->at(CarbonImmutable::parse('2026-10-06 14:00', 'UTC'))->create();
        app(TransitionAppointment::class)($appointment, AppointmentStatus::Cancelled, $this->actor, 'Unwell', CancellationKind::Client);
        $this->history = AppointmentStatusHistory::query()->sole();
    }

    #[Test]
    public function the_model_refuses_to_update_a_history_row(): void
    {
        $this->expectException(LogicException::class);

        $this->history->forceFill(['reason' => 'Rewritten'])->save();
    }

    #[Test]
    public function the_model_refuses_to_delete_a_history_row(): void
    {
        $this->expectException(LogicException::class);

        $this->history->delete();
    }

    #[Test]
    public function the_database_refuses_an_update_that_bypasses_the_model(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/insert-only/');

        DB::table('appointment_status_histories')->where('id', $this->history->id)->update(['reason' => 'Rewritten']);
    }

    #[Test]
    public function the_database_refuses_a_delete_that_bypasses_the_model(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/insert-only/');

        DB::table('appointment_status_histories')->where('id', $this->history->id)->delete();
    }

    #[Test]
    public function occupying_mirrors_the_statuses_of_the_exclusion_constraint(): void
    {
        $definition = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'appointments_no_clinician_overlap'"
        )->def;

        preg_match_all("/'([a-z_]+)'::/", $definition, $matches);
        $constrained = array_values(array_unique($matches[1]));
        sort($constrained);
        $occupying = AppointmentStatus::OCCUPYING;
        sort($occupying);

        $this->assertSame($occupying, $constrained);
    }
}
