<?php

namespace App\Domain\Scheduling;

use App\Domain\Shared\LabelledEnum;

/** Who made the booking. Anything but staff must fit published online availability. */
enum AppointmentSource: string
{
    use LabelledEnum;

    case Staff = 'staff';
    case Portal = 'portal';
    case PublicBooking = 'public_booking';
    case Waitlist = 'waitlist';

    /** Self-service bookings: must match an online slot and can never double-book. */
    public function isOnline(): bool
    {
        return $this !== self::Staff;
    }

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Portal => 'Client portal',
            self::PublicBooking => 'Online booking',
            self::Waitlist => 'Waitlist',
        };
    }
}
