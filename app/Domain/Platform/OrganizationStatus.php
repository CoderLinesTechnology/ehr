<?php

namespace App\Domain\Platform;

use App\Domain\Shared\LabelledEnum;

enum OrganizationStatus: string
{
    use LabelledEnum;

    case Pending = 'pending';
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    /** Staff can use the application. */
    public function allowsAccess(): bool
    {
        return in_array($this, [self::Trial, self::Active], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Trial, self::Active, self::Cancelled],
            self::Trial => [self::Active, self::Suspended, self::Cancelled],
            self::Active => [self::Suspended, self::Cancelled],
            self::Suspended => [self::Active, self::Trial, self::Cancelled, self::Archived],
            self::Cancelled => [self::Active, self::Archived],
            self::Archived => [self::Active],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Transitions that take access away and therefore need a reason and password confirmation. */
    public function isRestrictive(): bool
    {
        return in_array($this, [self::Suspended, self::Archived, self::Cancelled], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Trial => 'info',
            self::Pending => 'warning',
            self::Suspended => 'danger',
            self::Archived, self::Cancelled => 'neutral',
        };
    }

    public function actionLabel(): string
    {
        return match ($this) {
            self::Pending => 'Mark pending',
            self::Trial => 'Start trial',
            self::Active => 'Activate',
            self::Suspended => 'Suspend',
            self::Archived => 'Archive',
            self::Cancelled => 'Cancel',
        };
    }
}
