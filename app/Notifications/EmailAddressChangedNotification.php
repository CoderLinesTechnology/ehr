<?php

namespace App\Notifications;

use App\Domain\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the PREVIOUS address when an account's sign-in email changes. */
final class EmailAddressChangedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $newEmail) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $platform = (string) app(SettingsService::class)->platform('platform.name');

        return (new MailMessage)
            ->subject("Your {$platform} sign-in email was changed")
            ->line("The email address used to sign in to your {$platform} account was changed to ".self::mask($this->newEmail).'.')
            ->line('If you did not make this change, contact your organization administrator or platform support immediately.');
    }

    /** j***@example.com — enough to recognise, not enough to harvest. */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
