@php
    $format = app(\App\Support\Formatter::class);
    $startsAt = $format->localDate($details->startsAt, $details->timezone);
    $range = $format->time($details->startsAt, $details->timezone).' – '.$format->time($details->endsAt, $details->timezone);
    $location = 'Telehealth'.($details->vendor === 'Video' ? '' : ' ('.$details->vendor.')');
    $size = static fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    $clientUrl = $canViewClient && \Illuminate\Support\Facades\Gate::allows('viewAny', \App\Models\Client::class) ? route('app.clients.show', ['client' => $details->clientId]) : null;
    $messageUrl = $canMessage ? route('app.messages.index') : null;
    $nextUrl = $canBook ? route('app.appointments.create', ['client' => $details->clientId, 'service' => $details->serviceId, 'clinician' => $details->clinicianId, 'modality' => 'telehealth']) : null;
    $calendarUrl = $canCalendar ? route('app.calendar.index') : route('app.telehealth.index');
    $tasksUrl = \Illuminate\Support\Facades\Route::has('app.tasks.index') ? route('app.tasks.index') : null;
    $recording = $details->recordings[0] ?? null;
    $showMedia = $clinical && ($details->recordings !== [] || $details->transcripts !== [] || $session->consent_to_record);
    $tiles = array_values(array_filter([
        $clientUrl ? ['icon' => 'users', 'label' => 'View Client Record', 'href' => $clientUrl] : null,
        $messageUrl ? ['icon' => 'message-circle', 'label' => 'Send Message', 'href' => $messageUrl] : null,
        $nextUrl ? ['icon' => 'calendar-check', 'label' => 'Schedule Next Session', 'href' => $nextUrl] : null,
    ]));
@endphp
<x-layouts.app title="Session Completed">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/telehealth.css') }}?v={{ filemtime(public_path('css/screens/telehealth.css')) }}">
    @endpush

    <div class="ts">
        <div class="ts-main">
            <a href="{{ $calendarUrl }}" class="tj-back"><x-ui.icon name="arrow-left" :size="13" :stroke="2.2" />Back to Appointments</a>

            <header class="ts-head">
                <span class="ts-head__tick"><x-ui.icon name="check" :size="34" :stroke="2.6" /></span>
                <div>
                    <h1 class="ts-head__title">Session Completed</h1>
                    <p class="ts-head__text">Your telehealth session with {{ $details->clientName }} has ended.</p>
                    <p class="ts-head__when">{{ $startsAt }} <i aria-hidden="true">•</i> {{ $range }} &nbsp;({{ $details->durationLabel() }})</p>
                </div>
            </header>

            <section class="ts-card ts-client" aria-label="Client">
                <div class="ts-client__top">
                    @include('app.telehealth.partials-avatar', ['name' => $details->clientName, 'number' => $details->clientNumber, 'size' => 'xl', 'class' => 'ts-client__avatar'])
                    <div>
                        <h2 class="ts-client__name">{{ $details->clientName }}</h2>
                        <p class="ts-client__id">Client <i aria-hidden="true">•</i> {{ $details->clientNumber }}</p>
                        <p class="ts-client__chips">
                            <span class="ts-chip"><x-ui.icon name="calendar" :size="12" />{{ $details->serviceName }}</span>
                            <span class="ts-chip"><x-ui.icon name="video" :size="12" />Video</span>
                        </p>
                    </div>
                </div>
                <ul class="ts-tiles">
                    @foreach ($tiles as $tile)
                        <li><a href="{{ $tile['href'] }}" class="ts-tile"><x-ui.icon :name="$tile['icon']" :size="20" :stroke="1.8" /><span>{{ $tile['label'] }}</span></a></li>
                    @endforeach
                    <li>
                        <x-ui.dropdown class="ts-more" align="right">
                            <x-slot:trigger><span class="ts-tile"><x-ui.icon name="ellipsis" :size="20" :stroke="2" /><span>More</span></span></x-slot:trigger>
                            <x-ui.dropdown-item :href="route('app.appointments.show', ['appointment' => $details->appointmentId])" icon="calendar">Open appointment</x-ui.dropdown-item>
                            <x-ui.dropdown-item :href="route('app.telehealth.index')" icon="video">All sessions</x-ui.dropdown-item>
                        </x-ui.dropdown>
                    </li>
                </ul>
            </section>

            <section class="ts-card ts-summary" aria-labelledby="ts-summary-title">
                <h2 class="ts-card__title" id="ts-summary-title"><x-ui.icon name="file-text" :size="22" :stroke="1.8" />Session Summary</h2>
                <div class="ts-summary__grid">
                    <dl class="ts-facts">
                        <div><dt>Session Type</dt><dd>{{ $details->serviceName }}</dd></div>
                        <div><dt>Duration</dt><dd>{{ $details->durationLabel() }}</dd></div>
                        <div><dt>Provider</dt><dd>{{ $details->clinicianName }}</dd></div>
                        <div><dt>Location</dt><dd><x-ui.icon name="video" :size="16" :stroke="1.9" />{{ $location }}</dd></div>
                    </dl>
                    <div class="ts-notes">
                        <label for="ts-notes-field" class="ts-notes__label">Notes</label>
                        @if ($clinical)
                            <form method="POST" action="{{ route('app.telehealth.notes.update', ['session' => $session]) }}" class="ts-notes__form" data-submit-once>
                                @csrf @method('PUT')
                                <textarea id="ts-notes-field" name="notes" class="ts-notes__field" rows="6" maxlength="{{ \App\Domain\Telehealth\SaveSessionNotes::MAX_LENGTH }}" placeholder="Add session notes…" aria-describedby="ts-notes-error">{{ old('notes', $details->notes) }}</textarea>
                                <button type="submit" class="ts-notes__save">Save notes</button>
                            </form>
                            @error('notes')<p class="tj-error" id="ts-notes-error" role="alert">{{ $message }}</p>@enderror
                        @else
                            <p class="ts-notes__field ts-notes__field--static">Session notes are visible to the session's clinician.</p>
                        @endif
                    </div>
                </div>
            </section>

            @if ($showMedia)
                <section class="ts-card ts-media" aria-labelledby="ts-media-title">
                    <h2 class="ts-card__title" id="ts-media-title"><x-ui.icon name="video" :size="22" :stroke="1.8" />Recording &amp; Transcript
                        @if ($session->consent_to_record)<span class="ts-consent"><x-ui.icon name="shield-check" :size="12" :stroke="2.2" />Client consented to recording</span>@endif
                    </h2>
                    <ul class="ts-media__list">
                        @foreach ($details->recordings as $rec)
                            <li class="ts-media__row">
                                <span class="ts-media__icon ts-media__icon--play"><x-ui.icon name="play" :size="16" :stroke="2.4" /></span>
                                <div class="ts-media__text">
                                    <b>Session Recording</b>
                                    <small>{{ $details->durationLabel() }} <i aria-hidden="true">•</i> {{ $startsAt }} <i aria-hidden="true">•</i> {{ $size($rec->size_bytes) }}</small>
                                </div>
                                <a href="{{ route('app.telehealth.recordings.download', ['session' => $session, 'recording' => $rec]) }}" class="ts-btn" download><x-ui.icon name="download" :size="15" :stroke="2" />Download</a>
                            </li>
                        @endforeach
                        @foreach ($details->transcripts as $transcript)
                            <li class="ts-media__row">
                                <span class="ts-media__icon ts-media__icon--doc"><x-ui.icon name="file-text" :size="18" :stroke="1.9" /></span>
                                <div class="ts-media__text">
                                    <b>{{ $transcript->label() }}</b>
                                    <small>{{ $transcript->session_recording_id !== null ? 'Generated from recording' : ($transcript->source === \App\Domain\Telehealth\TranscriptSource::Ai ? 'Generated by AI' : 'From the video service') }}</small>
                                </div>
                                <a href="{{ route('app.telehealth.transcripts.show', ['session' => $session, 'transcript' => $transcript]) }}" class="ts-btn">View</a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($recordingOn && $session->consent_to_record && $details->recordings === [])
                        <form method="POST" action="{{ route('app.telehealth.recordings.store', ['session' => $session]) }}" enctype="multipart/form-data" class="ts-upload" data-submit-once>
                            @csrf
                            <label for="ts-recording">Attach the recording (audio or video, up to 300 MB)</label>
                            <input type="file" id="ts-recording" name="recording" accept="audio/*,video/mp4,video/webm,video/quicktime" required>
                            <button type="submit" class="ts-btn">Attach</button>
                            @error('recording')<p class="tj-error" role="alert">{{ $message }}</p>@enderror
                        </form>
                    @endif
                </section>
            @endif

            <section class="ts-card ts-next" aria-labelledby="ts-next-title">
                <h2 class="ts-card__title" id="ts-next-title"><x-ui.icon name="clipboard-check" :size="22" :stroke="1.8" />Next Steps</h2>
                <div class="ts-next__box">
                    <span class="ts-next__tile"><x-ui.icon name="calendar" :size="20" :stroke="1.9" /></span>
                    <div class="ts-next__text">
                        @if ($details->nextAppointment !== null)
                            <b>Follow-up Appointment</b>
                            <small>A follow-up session is scheduled for {{ $format->localDate($details->nextAppointment['startsAt'], $details->nextAppointment['timezone']) }} at {{ $format->time($details->nextAppointment['startsAt'], $details->nextAppointment['timezone']) }}.</small>
                        @else
                            <b>No follow-up scheduled</b>
                            <small>Book the next session while {{ $details->clientName }} is fresh in mind.</small>
                        @endif
                    </div>
                    @if ($details->nextAppointment !== null && $canCalendar)
                        <a href="{{ route('app.calendar.index', ['date' => $details->nextAppointment['startsAt']->setTimezone($details->nextAppointment['timezone'])->format('Y-m-d')]) }}" class="ts-btn">View Calendar</a>
                    @elseif ($details->nextAppointment === null && $nextUrl !== null)
                        <a href="{{ $nextUrl }}" class="ts-btn">Schedule</a>
                    @endif
                </div>
                <a href="{{ $calendarUrl }}" class="ts-return"><x-ui.icon name="arrow-left" :size="13" :stroke="2.2" />Return to Appointments</a>
            </section>
        </div>

        <aside class="ts-rail" aria-label="Session information">
            <section class="ts-card ts-details">
                <h2 class="ts-card__title"><x-ui.icon name="calendar" :size="22" :stroke="1.9" />Session Details</h2>
                <dl>
                    <div><x-ui.icon name="clock" :size="22" :stroke="1.7" /><dt>Date &amp; Time</dt><dd>{{ $startsAt }} <i aria-hidden="true">•</i> {{ $range }}</dd></div>
                    <div><x-ui.icon name="user" :size="22" :stroke="1.7" /><dt>Client</dt><dd>{{ $details->clientName }}</dd></div>
                    <div><x-ui.icon name="user" :size="22" :stroke="1.7" /><dt>Provider</dt><dd>{{ $details->clinicianName }}</dd></div>
                    <div><x-ui.icon name="calendar" :size="22" :stroke="1.7" /><dt>Service</dt><dd>{{ $details->serviceName }}</dd></div>
                    <div><x-ui.icon name="map-pin" :size="22" :stroke="1.7" /><dt>Location</dt><dd>{{ $location }}</dd></div>
                </dl>
            </section>

            <section class="ts-card ts-actions">
                <h2 class="ts-card__title"><x-ui.icon name="settings" :size="22" :stroke="1.9" />Quick Actions</h2>
                <ul>
                    @if ($clientUrl)<li><a href="{{ $clientUrl }}"><x-ui.icon name="users" :size="22" :stroke="1.8" />View Client Record</a></li>@endif
                    @if ($messageUrl)<li><a href="{{ $messageUrl }}"><x-ui.icon name="message-circle" :size="22" :stroke="1.8" />Send Message</a></li>@endif
                    @if ($clinical)<li><a href="#ts-notes-field"><x-ui.icon name="square-pen" :size="22" :stroke="1.8" />Add Note</a></li>@endif
                    @if ($tasksUrl)<li><a href="{{ $tasksUrl }}"><x-ui.icon name="square-check" :size="22" :stroke="1.8" />Create Task</a></li>@endif
                </ul>
            </section>

            <section class="ts-saved" role="status">
                <x-ui.icon name="shield-check" :size="26" :stroke="1.9" />
                <div>
                    <h2>Session saved</h2>
                    <p>Your session details, notes, and recording have been securely saved.</p>
                </div>
            </section>
        </aside>
    </div>
</x-layouts.app>
