<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Scheduling\Modality;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Domain\Telehealth\IssueCallPass;
use App\Domain\Telehealth\PrepareRoom;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Domain\Telehealth\Providers\VideoRoom;
use App\Domain\Telehealth\ReconcileSessions;
use App\Domain\Telehealth\SessionDetailsReader;
use App\Domain\Telehealth\SessionListReader;
use App\Domain\Telehealth\SessionStatus;
use App\Domain\Telehealth\TelehealthSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\CallPageRequest;
use App\Models\Appointment;
use App\Models\TelehealthSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * The sessions list, the join page, the call page and the completed page. Authorization is declared on the routes
 * (`can:` → TelehealthSessionPolicy); this only decides what the page offers. The join and call pages never fail
 * because of the video service: when it is not set up, unreachable, or the session is demo data, they say so.
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

    public function join(TelehealthSession $session, SessionDetailsReader $reader, PrepareRoom $prepare): View|RedirectResponse
    {
        if (! $session->status->isOpen()) {
            return $session->status === SessionStatus::Completed
                ? redirect()->route('app.telehealth.show', ['session' => $session])
                : redirect()->route('app.telehealth.index')->with('info', 'That session did not take place.');
        }

        // The room is prepared when the page opens, so its link can be copied for the client ahead of time.
        [$video, $room] = $this->video($session, fn () => $prepare($session));
        $clinical = Gate::allows('clinical', $session);

        return view('app.telehealth.join', [
            'session' => $session,
            'details' => $reader->read($session, tenant()->membership(), false),
            'video' => $video,
            'clientLink' => $room?->url,   // handed only to people who may join (the route's policy)
            'canManage' => Gate::allows('telehealth.manage'),
            'clinical' => $clinical,
            'recordingOn' => $clinical && app(TelehealthSettings::class)->recordingEnabled(tenant()->organizationOrFail()),
            'calendarUrl' => Route::has('app.calendar.index') && Gate::allows('viewAny', Appointment::class) ? route('app.calendar.index') : route('app.telehealth.index'),
            'supportEmail' => $this->supportEmail(),
        ]);
    }

    /**
     * The call: Daily Prebuilt framed in the page with a pass minted for this viewer (never stored or logged; the
     * page is no-store like every signed-in page). Only a running session has a call; others go to their page.
     * The page is also the "call host": while the user browses the app in its frame, the call floats (spec
     * docs/design/spec/screens/12-telehealth-call.md; behaviour in public/js/screens/telehealth.js).
     */
    public function call(CallPageRequest $request, TelehealthSession $session, SessionDetailsReader $reader, IssueCallPass $issue): View|RedirectResponse
    {
        if ($session->status !== SessionStatus::InProgress) {
            return $session->status === SessionStatus::Completed
                ? redirect()->route('app.telehealth.show', ['session' => $session])
                : redirect()->route('app.telehealth.join', ['session' => $session]);
        }

        // Opened inside a frame: the call page's own app frame, where the user browses while a call floats. A call
        // never nests or connects twice — no pass, no Daily: a stub asks the page around it to bring its call back.
        if (in_array($request->headers->get('Sec-Fetch-Dest'), ['iframe', 'frame'], true)) {
            return view('app.telehealth.call-open', ['session' => $session]);
        }

        $membership = tenant()->membership();
        $pass = null;
        [$video] = $this->video($session, function () use ($issue, $session, $membership, &$pass) {
            $pass = $issue($session, $membership);

            return null;
        });
        $clinical = Gate::allows('clinical', $session);

        return view('app.telehealth.call', [
            'session' => $session,
            'details' => $reader->read($session, $membership, false),
            'video' => $video,
            'pass' => $pass,
            'clientLink' => $pass !== null && is_string($session->join_url) ? $session->join_url : null,
            'canManage' => Gate::allows('telehealth.manage'),
            'clinical' => $clinical,
            'recordingOn' => app(TelehealthSettings::class)->recordingEnabled(tenant()->organizationOrFail()),
            // The page the user was browsing while the call floated (refresh restores it), validated: see CallPageRequest.
            'appPath' => $pass !== null ? $request->appPath(tenant()->organizationOrFail()->slug) : null,
        ]);
    }

    /**
     * What the page can say about video: 'ready', 'demo' (demo data never reaches the vendor), 'not_configured',
     * 'unavailable' (the vendor failed) or 'closed' (the room's time is over). $connect runs only when video can work.
     *
     * @param  \Closure(): ?VideoRoom  $connect
     * @return array{0: string, 1: ?VideoRoom}
     */
    private function video(TelehealthSession $session, \Closure $connect): array
    {
        if ($session->isDemo()) {
            return ['demo', null];
        }
        if (! app(ProviderRegistry::class)->get($session->provider_key)->status()->usable()) {
            return ['not_configured', null];
        }

        try {
            return ['ready', $connect()];
        } catch (DomainException $e) {
            return [match ($e->errorCode()) {
                'video_closed' => 'closed',
                'video_not_available' => 'not_configured',
                default => 'unavailable',
            }, null];
        }
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
