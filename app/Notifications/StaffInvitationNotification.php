<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation to join an organization as staff. Sent on demand to an email
 * address (the invitee may not have an account yet):
 *
 *   Notification::route('mail', $email)->notify(new StaffInvitationNotification(...));
 *
 * Carries only the organization name, inviter name and the acceptance link —
 * no client or clinical information. The link holds the invitation token, so
 * the queued payload is encrypted (only a hash of the token is stored).
 */
final class StaffInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly ?string $inviterName,
        public readonly string $acceptUrl,
        public readonly int $validDays,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $platform = (string) app(\App\Domain\Settings\SettingsService::class)->platform('platform.name');

        return (new MailMessage)
            ->subject("You're invited to join {$this->organizationName} on {$platform}")
            ->greeting('Hello,')
            ->line(($this->inviterName ? "{$this->inviterName} has" : 'You have been').
                " invited you to join {$this->organizationName} on {$platform}.")
            ->action('Accept invitation', $this->acceptUrl)
            ->line("This invitation expires in {$this->validDays} days. If you were not expecting it, you can ignore this email.");
    }
}
