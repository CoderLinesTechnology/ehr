@push('styles')<link rel="stylesheet" href="{{ asset('css/screens/settings.css') }}">@endpush
<x-layouts.auth :title="'Join '.$invitation['organization']">
    <div class="auth-card__header">
        <h1 class="auth-title">Join {{ $invitation['organization'] }}</h1>
        <p class="auth-lead">{{ $invitation['inviter'] ? $invitation['inviter'].' has invited you' : 'You have been invited' }} to join as a member of the team.</p>
    </div>
    <div class="inv-card">
        <dl class="inv-facts">
            <div><dt>Organization</dt><dd>{{ $invitation['organization'] }}</dd></div>
            <div><dt>Invited address</dt><dd>{{ $invitation['email'] }}</dd></div>
            @if ($invitation['roles'] !== [])<div><dt>Your role</dt><dd>{{ implode(', ', $invitation['roles']) }}</dd></div>@endif
            @if ($invitation['expires_at'])<div><dt>Valid until</dt><dd>{{ $invitation['expires_at']->format('j M Y') }}</dd></div>@endif
        </dl>

        @if ($emailMatches)
            <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}" data-submit-once>
                @csrf
                <x-ui.button type="submit" size="lg" :block="true">Accept invitation</x-ui.button>
            </form>
        @else
            <x-ui.alert tone="warning">You are signed in as {{ $userEmail }}, but this invitation was sent to {{ $invitation['email'] }}. Sign out and sign in with that address to accept it.</x-ui.alert>
            <form method="POST" action="{{ route('logout') }}">@csrf<x-ui.button type="submit" variant="secondary" :block="true">Sign out</x-ui.button></form>
        @endif
    </div>
</x-layouts.auth>
