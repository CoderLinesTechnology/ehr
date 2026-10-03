@php
    // Fortify answers with session('status') codes; everything else is shown exactly as given.
    $known = [
        'verification-link-sent' => ['success', 'A new verification link has been sent to your email address.'],
        'two-factor-authentication-enabled' => ['info', 'Two-factor authentication has been switched on. Finish setup by scanning the QR code and confirming a code.'],
        'two-factor-authentication-confirmed' => ['success', 'Two-factor authentication is now active on your account.'],
        'two-factor-authentication-disabled' => ['success', 'Two-factor authentication has been turned off.'],
        'recovery-codes-generated' => ['success', 'New recovery codes have been generated. The old ones no longer work.'],
        'password-updated' => ['success', 'Your password has been updated.'],
        'profile-information-updated' => ['success', 'Your profile has been updated.'],
    ];

    $toasts = [];
    $sticky = [];
    $push = static function (string $tone, string $message) use (&$toasts, &$sticky): void {
        if (in_array($tone, ['success', 'info'], true)) {
            $toasts[] = [$tone, $message];
        } else {
            $sticky[] = [$tone, $message];
        }
    };

    foreach (['success', 'error', 'warning', 'info'] as $key) {
        foreach ((array) session($key) as $message) {
            if (is_scalar($message) && trim((string) $message) !== '') {
                $push($key === 'error' ? 'danger' : $key, (string) $message);
            }
        }
    }
    $status = session('status');
    if (is_string($status) && trim($status) !== '') {
        $push(...($known[$status] ?? ['success', $status]));
    }
@endphp
{{-- Confirmations (success, info) float as toasts and tidy themselves away (JS only, paused on hover and focus); without JavaScript they sit in the page, persistent. Warnings and errors always stay in the page until dismissed. --}}
@if ($toasts !== [])
<div class="flash-region flash-region--toast" data-flash-region>
    @foreach ($toasts as [$tone, $message])
        <x-ui.alert :tone="$tone" :dismissible="true" data-autodismiss="9000">{{ $message }}</x-ui.alert>
    @endforeach
</div>
@endif
@if ($sticky !== [])
<div class="flash-region" data-flash-region>
    @foreach ($sticky as [$tone, $message])
        <x-ui.alert :tone="$tone" :dismissible="true">{{ $message }}</x-ui.alert>
    @endforeach
</div>
@endif
