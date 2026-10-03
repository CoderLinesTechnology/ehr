<?php

namespace App\Actions\Fortify;

use App\Domain\Settings\SettingsService;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /** Set by the invitation page for a guest; lowercase invited address. */
    public const INVITATION_SESSION_KEY = 'invitation.email';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Self-registration creates a global identity only; the organization is
     * created (or joined through an invitation) after the email is verified.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));

        // With self-service registration closed, only someone holding a staff
        // invitation for this exact address (stashed in the session when they
        // opened the invitation link) may create an account.
        $invitedEmail = session(self::INVITATION_SESSION_KEY);
        abort_if(
            $this->settings->platform('registration.mode') === 'closed'
                && ! (is_string($invitedEmail) && $invitedEmail === $input['email']),
            404,
        );

        Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:254',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (User::query()->whereRaw('lower(email) = ?', [$value])->exists()) {
                        $fail('An account with this email already exists.');
                    }
                },
            ],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'Please accept the Terms of Service and Privacy Policy.',
        ])->validate();

        try {
            return User::create([
                'name' => trim($input['name']),
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent registration for the same address.
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }
    }
}
