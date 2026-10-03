@php
    use App\Domain\Programs\EnrollmentEventType;

    $client = $enrollment->client;
    $status = $enrollment->status;
@endphp
<x-layouts.app :title="$client->displayName().' · '.$program->name">
    @push('styles')
        @include('app.programs._head')
    @endpush

    <div class="pr pr--page">
        <x-ui.page-header class="pr-pagehead" :title="$client->displayName()" :description="$program->name" icon="user-round">
            <x-slot:breadcrumbs><x-ui.breadcrumbs :items="[['label' => 'Programs', 'url' => route('app.programs.index')], ['label' => $program->name, 'url' => route('app.programs.show', ['program' => $program, 'tab' => 'participants'])], ['label' => $client->displayName()]]" /></x-slot:breadcrumbs>
            <x-slot:meta>
                <span class="pr-metaline">
                    <x-ui.badge :tone="$status->tone()">{{ $status->label() }}</x-ui.badge>
                    @if ($enrollment->level)<span class="pr-level">{{ $enrollment->level->name }}</span>@endif
                    <span>{{ $client->formattedNumber() }}</span>
                    @if ($enrollment->record_environment->value === 'demo')<x-ui.badge tone="demo" />@endif
                </span>
            </x-slot:meta>
            <x-slot:actions>
                @if (Route::has('app.clients.show') && Gate::allows('view', $client))
                    <x-ui.button variant="secondary" icon="user-round" :href="route('app.clients.show', ['client' => $client])">Client profile</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if ($canManage)
            <section class="pr-panel" aria-labelledby="pr-manage">
                <h2 id="pr-manage" class="pr-panel__title">Manage this enrollment</h2>

                @if ($levels->count() > 1)
                    <form method="POST" action="{{ route('app.programs.enrollments.level', ['program' => $program, 'enrollment' => $enrollment]) }}" class="pr-form" data-submit-once novalidate>
                        @csrf
                        <h3 class="pr-subtitle">Change level of care</h3>
                        <div class="pr-form__grid">
                            <x-ui.field label="New level" name="level_id" :required="true"><x-ui.select name="level_id" :options="$levels->where('id', '!=', $enrollment->current_level_id)->pluck('name', 'id')->all()" placeholder="Choose a level" required /></x-ui.field>
                            <x-ui.field label="Authorized by" name="authorized_by" :optional="true" help="Defaults to you."><x-ui.select name="authorized_by" :options="$staff" placeholder="Me" /></x-ui.field>
                            <x-ui.field class="pr-form__full" label="Reason" name="reason" :required="true"><x-ui.textarea name="reason" rows="2" maxlength="500" required /></x-ui.field>
                        </div>
                        <x-ui.button type="submit" variant="secondary">Change level</x-ui.button>
                    </form>
                @endif

                <div class="pr-actionrow">
                    @if ($status === \App\Domain\Programs\EnrollmentStatus::Active)
                        <x-ui.confirm-form :action="route('app.programs.enrollments.hold', ['program' => $program, 'enrollment' => $enrollment])" title="Put on hold" message="The participant stays enrolled and keeps their level." confirm-label="Put on hold" button-label="Put on hold" button-variant="secondary" tone="primary" reason-field="reason" reason-label="Reason (optional)" />
                    @else
                        <x-ui.confirm-form :action="route('app.programs.enrollments.resume', ['program' => $program, 'enrollment' => $enrollment])" title="Resume" message="The participant becomes active again." confirm-label="Resume" button-label="Resume" button-variant="secondary" tone="primary" />
                    @endif

                    <x-ui.confirm-form :action="route('app.programs.enrollments.discharge', ['program' => $program, 'enrollment' => $enrollment])" title="End enrollment" message="Choose how the enrollment ends. A discharge needs a reason. This cannot be undone: a returning client is admitted again as a new enrollment." confirm-label="End enrollment" button-label="Discharge or complete" reason-field="reason" reason-label="Reason (required for a discharge)">
                        <x-ui.field label="Outcome" name="outcome" :required="true">
                            <x-ui.radio-group name="outcome" :options="['completed' => 'Completed the program', 'discharged' => 'Discharged']" value="discharged" />
                        </x-ui.field>
                    </x-ui.confirm-form>
                </div>

                @if ($targets->isNotEmpty())
                    <form method="POST" action="{{ route('app.programs.enrollments.transfer', ['program' => $program, 'enrollment' => $enrollment]) }}" class="pr-form" data-submit-once novalidate>
                        @csrf
                        <h3 class="pr-subtitle">Transfer to another program</h3>
                        <div class="pr-form__grid">
                            <x-ui.field label="Program" name="program_id" :required="true"><x-ui.select name="program_id" :options="$targets->pluck('name', 'id')->all()" placeholder="Choose a program" required /></x-ui.field>
                            <x-ui.field label="Level of care there" name="level_id" :optional="true" help="Needed when that program has levels of care."><x-ui.select name="level_id" :options="$levelOptions" placeholder="None" /></x-ui.field>
                            <x-ui.field class="pr-form__full" label="Reason" name="reason" :optional="true"><x-ui.input name="reason" maxlength="500" /></x-ui.field>
                        </div>
                        <x-ui.button type="submit" variant="secondary">Transfer</x-ui.button>
                    </form>
                @endif
            </section>
        @endif

        <section class="pr-panel" aria-labelledby="pr-history">
            <h2 id="pr-history" class="pr-panel__title">History</h2>
            <ol class="pr-timeline">
                @foreach ($enrollment->events as $event)
                    <li>
                        <p class="pr-timeline__title">{{ $event->event_type->label() }}
                            @if ($event->event_type === EnrollmentEventType::LevelChanged)— {{ $event->fromLevel?->name ?? 'No level' }} → {{ $event->toLevel?->name }}@elseif ($event->event_type === EnrollmentEventType::Admitted && $event->toLevel) — {{ $event->toLevel->name }}@endif</p>
                        <p class="pr-muted">{{ fmt()->localDate($event->occurred_at) }}, {{ fmt()->time($event->occurred_at) }}@if ($event->actor) · {{ $event->actor->name }}@endif @if ($event->authorizedBy) · authorized by {{ $event->authorizedBy->displayName() }}@endif</p>
                        @if (filled($event->reason))<p class="pr-timeline__reason">{{ $event->reason }}</p>@endif
                    </li>
                @endforeach
            </ol>
        </section>
    </div>
</x-layouts.app>
