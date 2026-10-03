<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/** A client's e-mail address or phone number (client_contact_points.kind). */
enum ContactPointKind: string
{
    use LabelledEnum;

    case Email = 'email';
    case Phone = 'phone';

    /** The clients column that mirrors the primary one (search, list, reminders read it). */
    public function column(): string
    {
        return $this->value;
    }

    /** The form's list field: emails[] / phones[]. */
    public function inputKey(): string
    {
        return $this->value.'s';
    }

    public function defaultLabel(): ContactPointLabel
    {
        return $this === self::Phone ? ContactPointLabel::Mobile : ContactPointLabel::Home;
    }
}
