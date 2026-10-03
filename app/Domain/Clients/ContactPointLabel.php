<?php

namespace App\Domain\Clients;

use App\Domain\Shared\LabelledEnum;

/** Matches the client_contact_points_label_check constraint. */
enum ContactPointLabel: string
{
    use LabelledEnum;

    case Mobile = 'mobile';
    case Home = 'home';
    case Work = 'work';
    case Other = 'other';

    /** @return array<string, string> the labels offered for $kind (a "mobile" e-mail address means nothing) */
    public static function optionsFor(ContactPointKind $kind): array
    {
        $options = self::options();

        return $kind === ContactPointKind::Email ? array_diff_key($options, [self::Mobile->value => true]) : $options;
    }
}
