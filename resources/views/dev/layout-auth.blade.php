<x-layouts.auth title="Sign in" :preview-shell="$shell">
    <div class="auth-card__header">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-lead">This is the auth layout with a sample sign-in form.</p>
    </div>

    <form method="POST" action="{{ route('dev.styleguide.flash') }}" class="form" data-submit-once>
        @csrf
        <input type="hidden" name="kind" value="success">
        <x-ui.field label="Email address" name="sg_auth_email" :required="true">
            <x-ui.input type="email" name="sg_auth_email" autocomplete="off" required />
        </x-ui.field>
        <x-ui.field label="Password" name="sg_auth_password" :required="true">
            <x-ui.input type="password" name="sg_auth_password" autocomplete="off" required />
        </x-ui.field>
        <div class="auth-row">
            <span></span>
            <a href="#forgot" class="auth-row__link">Forgot password?</a>
        </div>
        <x-ui.button type="submit" size="lg" :block="true">Sign in</x-ui.button>
    </form>
    <p class="auth-switch"><a href="{{ route('dev.styleguide.index') }}">Back to the styleguide</a></p>
</x-layouts.auth>
