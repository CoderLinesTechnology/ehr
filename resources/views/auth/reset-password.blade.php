<x-layouts.auth title="Choose a new password">
    <div class="auth-card__header">
        <h1 class="auth-title">Choose a new password</h1>
        <p class="auth-lead">Pick a password you have not used anywhere else.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" class="form" data-submit-once>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-ui.field label="Email address" name="email" :required="true">
            <x-ui.input type="email" name="email" :value="$request->email" autocomplete="username" autocapitalize="none" spellcheck="false" :readonly="filled($request->email)" required />
        </x-ui.field>

        <x-ui.field label="New password" name="password" :required="true">
            <x-ui.input type="password" name="password" autocomplete="new-password" :autofocus="true" required />
        </x-ui.field>

        <x-ui.field label="Confirm new password" name="password_confirmation" :required="true">
            <x-ui.input type="password" name="password_confirmation" autocomplete="new-password" required />
        </x-ui.field>

        <x-ui.button type="submit" size="lg" :block="true">Reset password</x-ui.button>
    </form>

    <p class="auth-switch"><a href="{{ route('login') }}">Back to sign in</a></p>
</x-layouts.auth>
