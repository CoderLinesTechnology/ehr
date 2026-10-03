<x-layouts.auth title="Two-factor authentication">
    <div class="auth-card__header">
        <span class="auth-card__icon"><x-ui.icon name="smartphone" :size="24" /></span>
        <h1 class="auth-title">Two-factor authentication</h1>
        <p class="auth-lead">Enter the 6-digit code from your authenticator app to finish signing in.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login') }}" class="form" data-submit-once>
        @csrf

        <x-ui.field label="Authentication code" name="code">
            <x-ui.input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" autocapitalize="none" spellcheck="false" autofocus />
        </x-ui.field>

        <x-ui.button type="submit" size="lg" :block="true">Verify and sign in</x-ui.button>
    </form>

    {{-- A plain <details> and a second form: switching to a recovery code needs no JavaScript. --}}
    <details class="disclosure" @if (($errors ?? null)?->has('recovery_code')) open @endif>
        <summary class="disclosure__summary">Use a recovery code instead</summary>
        <form method="POST" action="{{ route('two-factor.login') }}" class="form disclosure__body" data-submit-once>
            @csrf

            <x-ui.field label="Recovery code" name="recovery_code" help="Each recovery code works once. Keep using your authenticator app when you can.">
                <x-ui.input name="recovery_code" autocomplete="off" autocapitalize="none" spellcheck="false" />
            </x-ui.field>

            <x-ui.button type="submit" variant="secondary" :block="true">Sign in with recovery code</x-ui.button>
        </form>
    </details>

    <p class="auth-switch"><a href="{{ route('login') }}">Cancel and return to sign in</a></p>
</x-layouts.auth>
