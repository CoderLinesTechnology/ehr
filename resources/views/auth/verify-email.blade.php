@php
    $email = auth()->user()?->email;
@endphp
<x-layouts.auth title="Verify your email">
    <div class="auth-card__header">
        <span class="auth-card__icon"><x-ui.icon name="mail" :size="24" /></span>
        <h1 class="auth-title">Check your email</h1>
        <p class="auth-lead">
            We sent a verification link @if (filled($email))to <strong>{{ $email }}</strong>@else to your email address @endif.
            Open it to finish setting up your account.
        </p>
    </div>

    <div class="stack">
        <form method="POST" action="{{ route('verification.send') }}" data-submit-once>
            @csrf
            <x-ui.button type="submit" size="lg" :block="true">Resend verification email</x-ui.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-ui.button type="submit" variant="ghost" :block="true">Sign out</x-ui.button>
        </form>
    </div>
</x-layouts.auth>
