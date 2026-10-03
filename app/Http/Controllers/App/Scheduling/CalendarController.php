<?php

namespace App\Http\Controllers\App\Scheduling;

use App\Domain\Scheduling\AppointmentStatus;
use App\Domain\Scheduling\Calendar\CalendarFilters;
use App\Domain\Scheduling\Calendar\CalendarGrid;
use App\Domain\Scheduling\Calendar\CalendarQuery;
use App\Domain\Scheduling\Calendar\CalendarRange;
use App\Domain\Scheduling\Calendar\EventFamily;
use App\Domain\Scheduling\Modality;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The calendar page: day / week / month, today's table and the right rail. Read-only; the
 * appointment screens own every change. Authorization is the route's `can:viewAny`; what is
 * visible is decided by CalendarQuery (own appointments vs everyone's).
 */
final class CalendarController
{
    public function __invoke(Request $request, CalendarQuery $calendar): View
    {
        $tz = $calendar->timezone();
        $now = CarbonImmutable::now($tz);
        $today = $now->format('Y-m-d');
        $filters = CalendarFilters::from($request->query(), $now);
        $range = CalendarRange::for($filters, $tz, $calendar->weekStartsOn());
        [$startHour, $endHour] = $calendar->dayHours();
        $options = $calendar->options();
        $events = $calendar->appointments($filters, $range);

        $data = ['events' => $events];
        if ($filters->view === 'week') {
            $data['columns'] = CalendarGrid::week($events, $range, $startHour, $endHour, $today);
        } elseif ($filters->view === 'day') {
            $clinicians = $filters->clinician !== null
                ? array_intersect_key($options['clinicians'], [$filters->clinician => true])
                : $options['clinicians'];
            $data['columns'] = CalendarGrid::clinicians($events, $clinicians, $startHour, $endHour);
        } else {
            $data['monthDays'] = CalendarGrid::month($events, $range, $filters->date, $today);
        }

        // The mini calendar browses on its own (?mini=YYYY-MM) without moving the main view.
        $mini = $request->query('mini');
        $miniMonth = is_string($mini) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mini) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $mini, 'UTC')
            : $filters->date->startOfMonth();
        $miniStart = $miniMonth->startOfWeek($calendar->weekStartsOn() === 7 ? CarbonImmutable::SUNDAY : CarbonImmutable::MONDAY);
        $marked = $calendar->markedDates($miniStart, $miniStart->addDays(42));

        $rangeIncludesToday = $today >= $range->first()->format('Y-m-d') && $today <= $range->last()->format('Y-m-d');

        return view('app.calendar.index', $data + [
            'filters' => $filters,
            'range' => $range,
            'today' => $today,
            'startHour' => $startHour,
            'endHour' => $endHour,
            'options' => $options,
            'label' => self::label($filters, $range),
            'prevUrl' => route('app.calendar.index', $filters->query(date: self::step($filters, -1))),
            'nextUrl' => route('app.calendar.index', $filters->query(date: self::step($filters, 1))),
            'todayUrl' => $rangeIncludesToday ? null : route('app.calendar.index', $filters->query(date: $today)),
            'todayAppointments' => $calendar->today($filters, $now),
            'mini' => [
                'month' => $miniMonth,
                'start' => $miniStart,
                'weeks' => (int) ceil(((int) $miniStart->diffInDays($miniMonth) + $miniMonth->daysInMonth) / 7),
                'marked' => array_map(fn ($clinicianId) => EventFamily::forColor($options['colors'][$clinicianId] ?? null), $marked),
                'prev' => route('app.calendar.index', $filters->query() + ['mini' => $miniMonth->subMonthNoOverflow()->format('Y-m')]),
                'next' => route('app.calendar.index', $filters->query() + ['mini' => $miniMonth->addMonthNoOverflow()->format('Y-m')]),
            ],
            'statuses' => collect(AppointmentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(),
            'modalities' => collect(Modality::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()])->all(),
            'canCreate' => Gate::allows('create', Appointment::class),
            'canManageAvailability' => Gate::any(['availability.manage_own', 'availability.manage_all']),
            'canCreateClient' => Gate::allows('clients.create') && Route::has('app.clients.create'),
            'seesAll' => $calendar->seesAll(),
        ]);
    }

    private static function step(CalendarFilters $filters, int $direction): string
    {
        $next = match ($filters->view) {
            'day' => $filters->date->addDays($direction),
            'month' => $filters->date->startOfMonth()->addMonthsNoOverflow($direction),
            default => $filters->date->addWeeks($direction),
        };

        return $next->format('Y-m-d');
    }

    /** "Apr 27 – May 3, 2025" / "Mon, Apr 28, 2025" / "April 2025" (SPEC: en dash with spaces). */
    private static function label(CalendarFilters $filters, CalendarRange $range): string
    {
        if ($filters->view === 'day') {
            return $filters->date->format('D, M j, Y');
        }
        if ($filters->view === 'month') {
            return $filters->date->format('F Y');
        }
        [$a, $b] = [$range->first(), $range->last()];

        return $a->year === $b->year
            ? $a->format('M j').' – '.$b->format('M j, Y')
            : $a->format('M j, Y').' – '.$b->format('M j, Y');
    }
}
