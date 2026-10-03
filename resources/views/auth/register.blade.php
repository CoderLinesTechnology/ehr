@php
    // $shell is only visible here if the composer is also bound to this view; the layout footer links to the same pages either way.
    $termsUrl = $shell['legal']['termsUrl'] ?? null;
    $privacyUrl = $shell['legal']['privacyUrl'] ?? null;
@endphp
<x-layouts.auth title="Create your account">
    <div class="auth-card__header">
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-lead">Start with your own sign-in. You will set up your organization after you verify your email.</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="form" data-submit-once>
        @csrf

        <x-ui.field label="Full name" name="name" :required="true">
            <x-ui.input name="name" autocomplete="name" autofocus required />
        </x-ui.field>

        <x-ui.field label="Email address" name="email" :required="true">
            <x-ui.input type="email" name="email" autocomplete="email" autocapitalize="none" spellcheck="false" required />
        </x-ui.field>

        <x-ui.field label="Password" name="password" :required="true" help="At least 12 characters, with letters and numbers. A password manager helps.">
            <x-ui.input type="password" name="password" autocomplete="new-password" required />
        </x-ui.field>

        <x-ui.field label="Confirm password" name="password_confirmation" :required="true">
            <x-ui.input type="password" name="password_confirmation" autocomplete="new-password" required />
        </x-ui.field>

        <x-ui.checkbox name="terms" :hidden-default="false" required>
            <x-slot:label>I agree to the @if (filled($termsUrl))<a href="{{ $termsUrl }}" target="_blank" rel="noopener noreferrer">Terms of Service</a>@else Terms of Service @endif and @if (filled($privacyUrl))<a href="{{ $privacyUrl }}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>@else Privacy Policy @endif</x-slot:label>
        </x-ui.checkbox>

        <x-ui.button type="submit" size="lg" :block="true">Create account</x-ui.button>
    </form>

    <p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
</x-layouts.auth>
