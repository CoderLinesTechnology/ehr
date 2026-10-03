<x-layouts.auth title="Sign in">
    <div class="auth-card__header">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-lead">Sign in to continue to your workspace.</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="form" data-submit-once>
        @csrf

        <x-ui.field label="Email address" name="email" :required="true">
            <x-ui.input type="email" name="email" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required />
        </x-ui.field>

        <x-ui.field label="Password" name="password" :required="true">
            <x-ui.input type="password" name="password" autocomplete="current-password" required />
        </x-ui.field>

        <div class="auth-row">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="auth-row__link">Forgot password?</a>
            @endif
        </div>

        <x-ui.button type="submit" size="lg" :block="true">Sign in</x-ui.button>
    </form>

    @if (Route::has('register') && ($registrationOpen ?? true))
        <p class="auth-switch">New here? <a href="{{ route('register') }}">Create an account</a></p>
    @endif
</x-layouts.auth>
