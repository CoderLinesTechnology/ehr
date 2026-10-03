<x-layouts.auth title="Confirm your password">
    <div class="auth-card__header">
        <span class="auth-card__icon"><x-ui.icon name="lock" :size="24" /></span>
        <h1 class="auth-title">Confirm your password</h1>
        <p class="auth-lead">This area protects sensitive settings. Please confirm your password to continue.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="form" data-submit-once>
        @csrf

        <x-ui.field label="Password" name="password" :required="true">
            <x-ui.input type="password" name="password" autocomplete="current-password" autofocus required />
        </x-ui.field>

        <x-ui.button type="submit" size="lg" :block="true">Confirm</x-ui.button>
    </form>

    <p class="auth-switch"><button type="button" class="link-button" data-history-back hidden>Go back</button></p>
</x-layouts.auth>
