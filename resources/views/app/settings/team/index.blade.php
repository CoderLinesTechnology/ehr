@php
    $statusLabels = ['active' => 'Active', 'invited' => 'Invited', 'suspended' => 'Suspended', 'deactivated' => 'Deactivated'];
    $chip = fn (?string $s) => route('app.settings.team.index', array_filter(['status' => $s, 'q' => $search !== '' ? $search : null]));
@endphp
<x-layouts.app title="Team">
    @if ($canInvite)
        @push('settings-actions')<x-ui.button icon="user-plus" data-dialog-open="invite-dialog">Invite team member</x-ui.button>@endpush
    @endif
    @include('app.settings.partials.open', ['current' => 'team'])

    <form method="GET" action="{{ route('app.settings.team.index') }}" class="set-toolbar" role="search">
        <div class="set-toolbar__grow"><x-ui.input type="search" name="q" :value="$search" icon="search" placeholder="Search name or email" aria-label="Search the team" autocomplete="off" /></div>
        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        <x-ui.button type="submit" variant="secondary">Search</x-ui.button>
    </form>
    <div class="set-chips" style="margin-bottom: 14px">
        <a class="set-chip" href="{{ $chip(null) }}" @if (! $status) aria-current="true" @endif>All ({{ array_sum($counts) }})</a>
        @foreach ($statusLabels as $value => $label)
            <a class="set-chip" href="{{ $chip($value) }}" @if ($status === $value) aria-current="true" @endif>{{ $label }} ({{ $counts[$value] ?? 0 }})</a>
        @endforeach
    </div>
    @if ($seatLimit !== null)
        <p class="set-note" style="margin-bottom: 14px">{{ $seatsUsed }} of {{ $seatLimit }} staff seats are in use (active and invited people count).</p>
    @endif

    @if ($members->isEmpty())
        <x-ui.empty-state icon="users" title="Nobody matches" description="Try a different search or status." />
    @else
        <section class="set-card set-card--flush" aria-label="Team members">
            <ul class="set-list">
                @foreach ($members as $member)
                    @php
                        $invited = $member->status === \App\Domain\Identity\MembershipStatus::Invited;
                        $name = $member->professionalName();
                    @endphp
                    <li>
                        <div class="set-who set-list__main">
                            @if ($member->color)<span class="set-dot" style="--c: {{ preg_match('/^#[0-9a-f]{6}$/i', (string) $member->color) ? $member->color : '#cbd5e1' }}" title="Calendar colour"></span>@endif
                            <div style="min-width: 0">
                                <p class="set-list__title">{{ $name }}
                                    <x-ui.badge :tone="$member->status->tone()">{{ ucfirst($member->status->value) }}</x-ui.badge>
                                    @if ($member->id === $myMembershipId)<x-ui.badge tone="outline">You</x-ui.badge>@endif
                                </p>
                                <p class="set-list__meta">
                                    @unless ($invited){{ $member->user?->email }} · @endunless
                                    {{ $member->roles->pluck('name')->implode(', ') ?: 'No role' }}@if ($member->title) · {{ $member->title }}@endif
                                </p>
                            </div>
                        </div>
                        <div class="set-list__actions">
                            @if ($invited)
                                @can('resendInvitation', $member)
                                    <form method="POST" action="{{ route('app.settings.team.invitations.resend', ['invitation' => $member]) }}" data-submit-once>@csrf<x-ui.button type="submit" variant="secondary" size="sm" icon="send">Resend</x-ui.button></form>
                                @endcan
                                @can('revokeInvitation', $member)
                                    <x-ui.confirm-form :action="route('app.settings.team.invitations.revoke', ['invitation' => $member])" method="DELETE" :title="'Withdraw the invitation to '.$member->invited_email.'?'"
                                        message="The link in their email stops working. You can invite them again later." confirm-label="Withdraw invitation" button-label="Revoke" button-size="sm" button-variant="neutral" />
                                @endcan
                            @else
                                <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('app.settings.team.edit', ['member' => $member])">{{ Gate::allows('update', $member) ? 'Edit' : 'View' }}</x-ui.button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
        <div class="set-pager">
            @if ($members->onFirstPage())<span></span>@else<x-ui.button variant="secondary" size="sm" :href="$members->previousPageUrl()">Previous</x-ui.button>@endif
            @if ($members->hasMorePages())<x-ui.button variant="secondary" size="sm" :href="$members->nextPageUrl()">Next</x-ui.button>@endif
        </div>
    @endif
    @include('app.settings.partials.close')

    @if ($canInvite)
        <x-ui.drawer id="invite-dialog" title="Invite a team member" description="They get an email with a link to join. It works for 7 days." :open="$errors->any() && old('email') !== null">
            <form method="POST" action="{{ route('app.settings.team.invite') }}" id="invite-form" class="form" data-submit-once>
                @csrf
                <x-ui.field label="Email address" name="email" :required="true"><x-ui.input type="email" name="email" autocomplete="off" required /></x-ui.field>
                <x-ui.field label="Role" name="roles" :required="true" help="What this person may do. You can change it later.">
                    <div class="stack stack--sm" data-choice-group="1">
                        @foreach ($roleOptions as $role)
                            <x-ui.checkbox name="roles[]" :id="'invite-role-'.$loop->index" :value="$role['id']" :label="$role['name']" :help="$role['description']" :checked="false" :disabled="! $role['assignable']" />
                        @endforeach
                    </div>
                </x-ui.field>
                <x-ui.field label="Job title" name="title" :optional="true"><x-ui.input name="title" maxlength="100" /></x-ui.field>
                <x-ui.checkbox name="is_provider" label="Sees clients (shows up on the calendar and can be booked)" :checked="false" />
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
                <x-ui.button type="submit" form="invite-form">Send invitation</x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    @endif
</x-layouts.app>
