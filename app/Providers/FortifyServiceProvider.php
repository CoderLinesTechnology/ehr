<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Domain\Settings\SettingsService;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /** A real hash of a random secret, so a lookup miss costs the same as a hit. */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Forgot-password never reveals whether an address has an account.
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, GenericPasswordResetLinkResponse::class);

        // Disabled accounts cannot sign in. Unknown, disabled and wrong-password
        // attempts cost the same password hash and give the same generic error,
        // so neither the message nor the timing reveals which accounts exist.
        // "Remember me" is not offered: a long-lived cookie would defeat the
        // automatic logoff (session.lifetime idle + absolute lifetime).
        Fortify::authenticateUsing(function (Request $request) {
            $request->request->remove('remember');

            $email = $request->input('email');
            $password = $request->input('password');
            if (! is_string($email) || ! is_string($password)) {
                return null;
            }

            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
            $hash = $user?->password ?? self::dummyHash();
            $valid = Hash::check($password, $hash);

            return ($user !== null && $valid && ! $user->isDisabled()) ? $user : null;
        });

        $registrationOpen = fn () => app(SettingsService::class)->platform('registration.mode') !== 'closed'
            || is_string(session(CreateNewUser::INVITATION_SESSION_KEY));

        Fortify::loginView(fn () => view('auth.login', ['registrationOpen' => $registrationOpen()]));
        Fortify::registerView(function () use ($registrationOpen) {
            abort_unless($registrationOpen(), 404);

            return view('auth.register');
        });
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        RateLimiter::for('login', function (Request $request) {
            $email = $request->input(Fortify::username());
            $email = is_string($email) ? Str::transliterate(Str::lower(trim($email))) : '';

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perHour(30)->by('login-account:'.$email),   // per-account ceiling across IPs
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $userId = (string) $request->session()->get('login.id', '');

            return [
                Limit::perMinute(5)->by('2fa:'.($userId !== '' ? $userId : $request->ip())),
                Limit::perHour(20)->by('2fa-account:'.$userId),
            ];
        });
    }
}
