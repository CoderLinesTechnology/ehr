<x-layouts.auth title="Reset your password">
    <div class="auth-card__header">
        <h1 class="auth-title">Reset your password</h1>
        <p class="auth-lead">Enter the email address on your account and we will send you a link to choose a new password.</p>
    </div>

    <form method="POST" action="{{ route('password.email') }}" class="form" data-submit-once>
        @csrf

        <x-ui.field label="Email address" name="email" :required="true">
            <x-ui.input type="email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false" autofocus required />
        </x-ui.field>

        <x-ui.button type="submit" size="lg" :block="true">Email me a reset link</x-ui.button>
    </form>

    <p class="auth-switch"><a href="{{ route('login') }}">Back to sign in</a></p>
</x-layouts.auth>
