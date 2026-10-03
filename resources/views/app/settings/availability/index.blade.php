@php
    $tz = $timezone;
    $editRule = $editing;
    $hm = fn ($t) => substr((string) $t, 0, 5);
    $clock = fn ($t) => \Carbon\CarbonImmutable::createFromFormat('!H:i', $hm($t), 'UTC')->format(fmt()->timeFormat());
    $byDay = $rules->groupBy('weekday');
    $today = \Carbon\CarbonImmutable::now($tz)->format('Y-m-d');
    $clinicianQuery = $selected ? ['clinician' => $selected->id] : [];
@endphp
<x-layouts.app title="Availability">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/calendar.css') }}?v={{ filemtime(public_path('css/screens/calendar.css')) }}">
    @endpush

    <x-ui.page-header title="Availability" description="Weekly working hours and time off. Free appointment times are calculated from these." icon="clock" icon-shape="square" icon-tone="blue">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Settings', 'url' => \Illuminate\Support\Facades\Route::has('app.settings.index') ? route('app.settings.index') : null], ['label' => 'Availability']]" />
        </x-slot:breadcrumbs>
        @if ($canManageAll && $providers->count() > 1)
            <x-slot:actions>
                <form method="GET" action="{{ route('app.settings.availability.index') }}" class="appt-inline">
                    <x-ui.field label="Clinician" name="clinician" id="av-clin">
                        <x-ui.select name="clinician" id="av-clin" :value="$selected?->id" :options="$providers->mapWithKeys(fn ($m) => [$m->id => $m->professionalName()])->all()" data-autosubmit />
                    </x-ui.field>
                    <noscript><x-ui.button type="submit" variant="secondary">Show</x-ui.button></noscript>
                </form>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($selected === null)
        <x-ui.card>
            <x-ui.empty-state icon="clock" title="No clinician to schedule" description="Availability belongs to a clinician. Mark a team member as a provider first, then set their hours here." />
        </x-ui.card>
    @else
        <div class="avail-grid">
            <div class="appt-stack">
                <x-ui.card :title="'Weekly hours · '.$selected->professionalName()" :description="'Times are local to each place. The organization is in '.$tz.'.'">
                    @forelse ($weekdays as $number => $dayName)
                        @if ($byDay->has($number))
                            <div class="avail-day">
                                <h3>{{ $dayName }}</h3>
                                <div>
                                    @foreach ($byDay[$number] as $rule)
                                        <div class="avail-rule">
                                            <span class="avail-rule__time">{{ $clock($rule->start_time) }} – {{ $clock($rule->end_time) }}</span>
                                            <span class="avail-rule__meta">
                                                {{ $rule->modality->label() }}@if ($rule->location) · {{ $rule->location->name }}@endif
                                                @if ($rule->repeat_every_weeks > 1) · every {{ $rule->repeat_every_weeks }} weeks @endif
                                                · from {{ fmt()->date($rule->effective_from) }}@if ($rule->effective_until) until {{ fmt()->date($rule->effective_until) }}@endif
                                                @if ($rule->services->isNotEmpty()) · {{ $rule->services->pluck('name')->implode(', ') }}@endif
                                            </span>
                                            @unless ($rule->is_active)<x-ui.badge tone="neutral">Paused</x-ui.badge>@endunless
                                            @unless ($rule->is_bookable_online)<x-ui.badge tone="outline">Staff only</x-ui.badge>@endunless
                                            <span class="avail-rule__actions">
                                                <x-ui.button size="xs" variant="secondary" :href="route('app.settings.availability.index', $clinicianQuery + ['rule' => $rule->id]).'#rule-form'">Edit</x-ui.button>
                                                <x-ui.confirm-form :action="route('app.settings.availability.rules.destroy', ['rule' => $rule])" method="DELETE" title="Remove this availability?"
                                                    message="Free times in this window stop being offered. Appointments already booked are not affected." confirm-label="Remove" button-label="Remove" button-size="xs" button-variant="secondary" />
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @empty
                    @endforelse
                    @if ($rules->isEmpty())
                        <x-ui.empty-state icon="clock" title="No working hours yet" description="Add a weekly window so clients can be booked with this clinician." />
                    @endif
                </x-ui.card>

                <x-ui.card title="Blocked time" description="Leave, holidays and closures. Nothing can be booked in these periods.">
                    @if ($blocked->isEmpty())
                        <p class="text-muted text-sm">Nothing is blocked.</p>
                    @else
                        <ul class="appt-history">
                            @foreach ($blocked as $block)
                                @php
                                    $start = \Carbon\CarbonImmutable::instance($block->starts_at)->setTimezone($tz);
                                    $end = \Carbon\CarbonImmutable::instance($block->ends_at)->setTimezone($tz);
                                @endphp
                                <li>
                                    <span class="avail-rule">
                                        <strong>{{ $block->title ?: $block->kind->label() }}</strong>
                                        <x-ui.badge tone="neutral">{{ $block->kind->label() }}</x-ui.badge>
                                        @if ($block->membership_id === null)<x-ui.badge tone="primary">Everyone</x-ui.badge>@endif
                                        <span class="avail-rule__actions">
                                            @if ($block->membership_id !== null || $canManageAll)
                                                <x-ui.confirm-form :action="route('app.settings.availability.blocked.destroy', ['blocked' => $block])" method="DELETE" title="Remove this blocked time?"
                                                    message="The time becomes bookable again." confirm-label="Remove" button-label="Remove" button-size="xs" button-variant="secondary" />
                                            @endif
                                        </span>
                                    </span>
                                    <span class="avail-rule__meta">
                                        @if ($block->all_day)
                                            {{ fmt()->date($start) }}@if ($end->subSecond()->format('Y-m-d') !== $start->format('Y-m-d')) – {{ fmt()->date($end->subSecond()) }}@endif · all day
                                        @else
                                            {{ fmt()->date($start) }} · {{ $start->format(fmt()->timeFormat()) }} – {{ $end->format(fmt()->timeFormat()) }}
                                        @endif
                                        @if ($block->location) · {{ $block->location->name }}@endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>

            <div class="appt-stack">
                <section class="card appt-card" id="rule-form" aria-labelledby="rule-title">
                    <h2 class="appt-card__title" id="rule-title">{{ $editRule ? 'Edit weekly window' : 'Add weekly window' }}</h2>
                    <form method="POST" action="{{ $editRule ? route('app.settings.availability.rules.update', ['rule' => $editRule]) : route('app.settings.availability.rules.store') }}" class="form" data-submit-once>
                        @csrf
                        @if ($editRule)@method('PUT')@endif
                        <input type="hidden" name="membership_id" value="{{ $selected->id }}">
                        <div class="form-grid">
                            <x-ui.field label="Day" name="weekday" id="r-day" required>
                                <x-ui.select name="weekday" id="r-day" :value="$editRule?->weekday ?? 1" :options="$weekdays" required />
                            </x-ui.field>
                            <x-ui.field label="Applies to" name="modality" id="r-mod" required>
                                <x-ui.select name="modality" id="r-mod" :value="$editRule?->modality->value ?? 'in_person'" :options="$modalities" required />
                            </x-ui.field>
                            <x-ui.field label="From" name="start_time" id="r-start" required>
                                <x-ui.input type="time" name="start_time" id="r-start" step="300" :value="$editRule ? $hm($editRule->start_time) : '09:00'" required />
                            </x-ui.field>
                            <x-ui.field label="Until" name="end_time" id="r-end" required>
                                <x-ui.input type="time" name="end_time" id="r-end" step="300" :value="$editRule ? $hm($editRule->end_time) : '17:00'" required />
                            </x-ui.field>
                            <x-ui.field label="Location" name="location_id" id="r-loc" help="Needed for in-person hours.">
                                <x-ui.select name="location_id" id="r-loc" :value="$editRule?->location_id" placeholder="None (telehealth)" :options="$locations" />
                            </x-ui.field>
                            <x-ui.field label="Repeats every" name="repeat_every_weeks" id="r-rep" required>
                                <x-ui.select name="repeat_every_weeks" id="r-rep" :value="$editRule?->repeat_every_weeks ?? 1" :options="array_combine(range(1, 8), array_map(fn ($n) => $n === 1 ? 'Week' : $n.' weeks', range(1, 8)))" required />
                            </x-ui.field>
                            <x-ui.field label="Starting" name="effective_from" id="r-from" required>
                                <x-ui.input type="date" name="effective_from" id="r-from" :value="$editRule?->effective_from?->format('Y-m-d') ?? $today" required />
                            </x-ui.field>
                            <x-ui.field label="Until (optional)" name="effective_until" id="r-until">
                                <x-ui.input type="date" name="effective_until" id="r-until" :value="$editRule?->effective_until?->format('Y-m-d')" />
                            </x-ui.field>
                        </div>
                        @if ($services->isNotEmpty())
                            <x-ui.field label="Services" name="service_ids" help="Leave all unticked to offer every service this clinician provides.">
                                <div class="choice-grid" data-choice-group="1">
                                    @foreach ($services as $service)
                                        <x-ui.checkbox name="service_ids[]" :value="$service->id" :label="$service->name" :checked="$editRule && $editRule->services->contains('id', $service->id)" />
                                    @endforeach
                                </div>
                            </x-ui.field>
                        @endif
                        <x-ui.checkbox name="is_bookable_online" label="Clients can book this online" :checked="$editRule?->is_bookable_online ?? true" />
                        <x-ui.checkbox name="is_active" label="Active" :checked="$editRule?->is_active ?? true" />
                        <div class="form-actions">
                            <x-ui.button type="submit">{{ $editRule ? 'Save changes' : 'Add availability' }}</x-ui.button>
                            @if ($editRule)<x-ui.button variant="secondary" :href="route('app.settings.availability.index', $clinicianQuery)">Cancel</x-ui.button>@endif
                        </div>
                    </form>
                </section>

                <section class="card appt-card" aria-labelledby="block-title">
                    <h2 class="appt-card__title" id="block-title">Block time off</h2>
                    <form method="POST" action="{{ route('app.settings.availability.blocked.store') }}" class="form" data-submit-once>
                        @csrf
                        <input type="hidden" name="membership_id" value="{{ $selected->id }}">
                        <div class="form-grid">
                            <x-ui.field label="Type" name="kind" id="b-kind" required>
                                <x-ui.select name="kind" id="b-kind" value="leave" :options="$kinds" required />
                            </x-ui.field>
                            <x-ui.field label="Title" name="title" id="b-title" optional>
                                <x-ui.input name="title" id="b-title" maxlength="120" />
                            </x-ui.field>
                            <x-ui.field label="Date" name="start_date" id="b-start" required>
                                <x-ui.input type="date" name="start_date" id="b-start" :value="$today" required />
                            </x-ui.field>
                            <x-ui.field label="Last day" name="end_date" id="b-end" optional help="For several days.">
                                <x-ui.input type="date" name="end_date" id="b-end" />
                            </x-ui.field>
                            <x-ui.field label="From" name="start_time" id="b-st" optional help="Leave both times empty for all day.">
                                <x-ui.input type="time" name="start_time" id="b-st" step="300" />
                            </x-ui.field>
                            <x-ui.field label="Until" name="end_time" id="b-et" optional>
                                <x-ui.input type="time" name="end_time" id="b-et" step="300" />
                            </x-ui.field>
                            <x-ui.field label="Location" name="location_id" id="b-loc" optional>
                                <x-ui.select name="location_id" id="b-loc" placeholder="Every location" :options="$locations" />
                            </x-ui.field>
                            @if ($canManageAll)
                                <x-ui.field label="Applies to" name="scope" id="b-scope">
                                    <x-ui.select name="scope" id="b-scope" value="clinician" :options="['clinician' => $selected->professionalName(), 'everyone' => 'Everyone (whole organization)']" />
                                </x-ui.field>
                            @endif
                        </div>
                        <div class="form-actions"><x-ui.button type="submit" variant="secondary">Block time</x-ui.button></div>
                    </form>
                </section>
            </div>
        </div>
    @endif
</x-layouts.app>
