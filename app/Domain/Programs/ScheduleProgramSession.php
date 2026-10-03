<?php

namespace App\Domain\Programs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\AccessGuard;
use App\Domain\Shared\DomainException;
use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\ProgramSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Puts a group session or activity on a program's schedule. Needs `programs.manage`.
 *
 *  - The program must be upcoming or active, and the session day inside the program's dates.
 *  - Date and times are wall-clock in the place's timezone (the location's, else the organization's) and are stored
 *    as UTC instants plus that timezone, like appointments.
 *  - Place: a location of the organization, or online. The facilitator is an active member.
 *
 * Audit: `program_session.scheduled` (title is the session's name, not clinical).
 */
final class ScheduleProgramSession
{
    public function __construct(private readonly AccessGuard $guard, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input  title, date (Y-m-d), start_time, end_time (H:i), place ('online' | location id | ''), facilitator_membership_id
     *
     * @throws DomainException
     */
    public function __invoke(Program $program, array $input): ProgramSession
    {
        $this->guard->requirePermission('programs.manage');
        $this->guard->assertInOrganization($program);

        $title = ProgramText::required($input['title'] ?? null, 120, 'title', 'session title');

        $place = (string) ($input['place'] ?? '');
        $online = $place === 'online';
        $location = null;
        if ($place !== '' && ! $online) {
            $location = Str::isUuid($place) ? Location::query()->active()->whereKey($place)->first() : null;
            if ($location === null) {
                throw new DomainException('Choose one of your locations, or Online.', 'place', 'place');
            }
        }
        $timezone = $location?->timezone ?: $this->guard->organization()->timezone;

        [$start, $end] = $this->instants($input, $timezone);

        $facilitator = null;
        if (filled($input['facilitator_membership_id'] ?? null)) {
            $id = (string) $input['facilitator_membership_id'];
            $facilitator = Str::isUuid($id) ? OrganizationMembership::query()->active()->whereKey($id)->first() : null;
            if ($facilitator === null) {
                throw new DomainException('Choose an active team member as facilitator.', 'facilitator', 'facilitator_membership_id');
            }
        }

        return DB::transaction(function () use ($program, $title, $start, $end, $timezone, $location, $online, $facilitator) {
            $locked = Program::query()->whereKey($program->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_sud_program) {
                $this->guard->requirePermission('programs.view_sud');
            }
            if (! in_array($locked->status, [ProgramStatus::Upcoming, ProgramStatus::Active], true)) {
                throw new DomainException('Sessions can only be added to programs that are upcoming or active.', 'program_closed');
            }
            $day = $start->setTimezone($timezone)->format('Y-m-d');
            if ($day < $locked->starts_on->format('Y-m-d') || ($locked->ends_on !== null && $day > $locked->ends_on->format('Y-m-d'))) {
                throw new DomainException('The session must fall within the program’s dates.', 'outside_program_dates', 'date');
            }

            $session = new ProgramSession;
            $session->forceFill([
                'program_id' => $locked->id, 'title' => $title, 'starts_at' => $start, 'ends_at' => $end, 'timezone' => $timezone,
                'location_id' => $location?->id, 'is_online' => $online, 'facilitator_membership_id' => $facilitator?->id,
                'created_by_user_id' => Auth::id(),
            ])->save();

            $this->audit->record(
                'program_session.scheduled',
                $session,
                after: ['title' => $title, 'starts_at' => $start->toIso8601String(), 'program' => $locked->is_sud_program ? null : $locked->name],
                summary: "Scheduled the session “{$title}”",
            );

            return $session;
        });
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC instants */
    private function instants(array $input, string $timezone): array
    {
        $day = is_string($input['date'] ?? null) ? trim($input['date']) : '';
        $parsedDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC') : false;
        if ($parsedDay === false || $parsedDay->format('Y-m-d') !== $day) {
            throw new DomainException('Enter a valid date.', 'invalid_datetime', 'date');
        }

        $make = function (string $key, string $label) use ($input, $day, $timezone): CarbonImmutable {
            $time = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            $value = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$day} {$time}", $timezone) : false;
            if ($value === false) {
                throw new DomainException("Enter a valid {$label}.", 'invalid_datetime', $key);
            }

            return $value->utc();
        };
        $start = $make('start_time', 'start time');
        $end = $make('end_time', 'end time');
        if ($end <= $start) {
            throw new DomainException('The session must end after it starts.', 'range', 'end_time');
        }

        return [$start, $end];
    }
}
