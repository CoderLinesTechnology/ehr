<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Notifications\EmailAddressChangedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Changing the sign-in address requires the current password (a hijacked
     * session cannot take the account over), re-verification, and a notice to
     * the previous address.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        $input['email'] = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $emailChanges = $input['email'] !== $user->email;

        Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required', 'string', 'email:rfc', 'max:254',
                function (string $attribute, mixed $value, \Closure $fail) use ($user) {
                    if (User::query()->whereRaw('lower(email) = ?', [$value])->whereKeyNot($user->id)->exists()) {
                        $fail('An account with this email already exists.');
                    }
                },
            ],
            'current_password' => [Rule::requiredIf($emailChanges), 'nullable', 'string', 'current_password:web'],
        ], [
            'current_password.required' => 'Enter your current password to change your email address.',
            'current_password.current_password' => 'The password is incorrect.',
        ])->validateWithBag('updateProfileInformation');

        if (! $emailChanges) {
            $user->forceFill(['name' => trim($input['name'])])->save();

            return;
        }

        $previous = $user->email;

        try {
            $user->forceFill([
                'name' => trim($input['name']),
                'email' => $input['email'],
                'email_verified_at' => null,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.'])
                ->errorBag('updateProfileInformation');
        }

        $user->sendEmailVerificationNotification();
        Notification::route('mail', $previous)->notify(new EmailAddressChangedNotification($input['email']));
    }
}
