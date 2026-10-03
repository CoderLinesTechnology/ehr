<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Modality;
use App\Domain\Settings\SettingsService;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\ReconcileSessions;
use App\Domain\Telehealth\SessionDetailsReader;
use App\Domain\Telehealth\SessionListReader;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Telehealth\TelehealthSettings;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The sessions list, the join page and the completed page. Authorization is declared on the routes
 * (`can:` → TelehealthSessionPolicy); this only decides which links the page offers.
 */
final class SessionController extends Controller
{
    public function index(Request $request, SessionListReader $reader, ReconcileSessions $reconcile): View
    {
        $reconcile();   // sessions for appointments whose modality was edited rather than rebooked (bounded, idempotent)
        $membership = tenant()->membership();
        $tab = is_string($request->query('tab')) ? $request->query('tab') : 'upcoming';
        $page = (int) $request->query('page', 1);

        $list = $reader->page($membership, $tab, $page);
        $next = $reader->next($membership);
        $canJoin = Gate::allows('telehealth.join');
        $nextUrl = $next !== null && $canJoin ? route('app.telehealth.join', ['session' => $next['id']]) : null;

        $calendar = Route::has('app.calendar.index') && Gate::allows('viewAny', Appointment::class);
        $book = Route::has('app.appointments.create') && Gate::allows('create', Appointment::class);

        return view('app.telehealth.index', [
            'list' => $list,
            'canJoin' => $canJoin,
            'nextUrl' => $nextUrl,
            'nextJoinable' => $next['joinable'] ?? false,
            'links' => [
                'start' => $nextUrl ?? ($book ? route('app.appointments.create', ['modality' => Modality::Telehealth->value]) : null),
                'calendar' => $calendar ? route('app.calendar.index', ['modality' => Modality::Telehealth->value]) : null,
                'check' => route('app.telehealth.check'),
                'settings' => Gate::allows('telehealth.manage') ? route('app.settings.telehealth.edit') : null,
                'appointments' => $calendar ? route('app.calendar.index') : null,
                'instructions' => Route::has('app.resources.index') && Gate::allows('resources.view') ? route('app.resources.index', ['q' => 'telehealth']) : null,
                'support' => $this->supportLink(),
                'directory' => Route::has('app.settings.team.index') && Gate::allows('team.view') ? route('app.settings.team.index') : null,
            ],
        ]);
    }

    /** The completed-session page; a session that has not finished leads to its join page, one that never happened to the list. */
    public function show(TelehealthSession $session, SessionDetailsReader $reader, AuditLogger $audit): View|RedirectResponse
    {
        if ($session->status->isOpen()) {
            return Gate::allows('join', $session)
                ? redirect()->route('app.telehealth.join', ['session' => $session])
                : redirect()->route('app.telehealth.index')->with('info', 'That session has not taken place yet.');
        }
        if ($session->status !== SessionStatus::Completed) {
            return redirect()->route('app.telehealth.index')->with('info', 'That session did not take place.');
        }

        $membership = tenant()->membership();
        $clinical = Gate::allows('clinical', $session);
        if ($clinical) {
            // Opening notes, recordings and transcripts is a sensitive read (HIPAA audit controls): who, when, which session.
            $audit->record('telehealth.session_clinical_viewed', subject: $session, summary: 'Telehealth session notes and recordings were opened');
        }

        return view('app.telehealth.show', [
            'session' => $session,
            'details' => $reader->read($session, $membership, $clinical),
            'clinical' => $clinical,
            'canMessage' => Route::has('app.messages.index') && Gate::allows('messages.client'),
            'canViewClient' => Route::has('app.clients.show'),
            'canBook' => Route::has('app.appointments.create') && Gate::allows('create', Appointment::class),
            'canCalendar' => Route::has('app.calendar.index') && Gate::allows('viewAny', Appointment::class),
            'recordingOn' => $clinical && app(TelehealthSettings::class)->recordingEnabled(tenant()->organizationOrFail()),
        ]);
    }

    public function join(TelehealthSession $session, SessionDetailsReader $reader): View|RedirectResponse
    {
        if (! $session->status->isOpen()) {
            return $session->status === SessionStatus::Completed
                ? redirect()->route('app.telehealth.show', ['session' => $session])
                : redirect()->route('app.telehealth.index')->with('info', 'That session did not take place.');
        }

        $membership = tenant()->membership();
        $clinical = Gate::allows('clinical', $session);
        $details = $reader->read($session, $membership, false);

        return view('app.telehealth.join', [
            'session' => $session,
            'details' => $details,
            // The meeting link is handed only to people who may join, and only if it still passes the host allowlist.
            'joinUrl' => $details->hasLink ? app(ProviderRegistry::class)->get($session->provider_key)->joinUrlFor($session, request()->user()) : null,
            'clinical' => $clinical,
            'recordingOn' => $clinical && app(TelehealthSettings::class)->recordingEnabled(tenant()->organizationOrFail()),
            'calendarUrl' => Route::has('app.calendar.index') && Gate::allows('viewAny', Appointment::class) ? route('app.calendar.index') : route('app.telehealth.index'),
            'supportEmail' => $this->supportEmail(),
        ]);
    }

    private function supportEmail(): ?string
    {
        $email = app(SettingsService::class)->platform('platform.support_email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function supportLink(): ?string
    {
        $email = $this->supportEmail();

        return $email === null ? null : 'mailto:'.$email;
    }
}
