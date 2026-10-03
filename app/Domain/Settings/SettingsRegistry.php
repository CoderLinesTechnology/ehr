<?php

namespace App\Domain\Settings;

use App\Domain\Saas\FeatureRegistry;
use InvalidArgumentException;

/**
 * Every configurable business value, declared once. Unknown keys cannot be
 * read or written. Add a key here (with a sensible default) before using it.
 */
final class SettingsRegistry
{
    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return self::$definitions ??= self::build();
    }

    public static function get(string $key): SettingDefinition
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string, SettingDefinition> */
    public static function forScope(string $scope, ?string $group = null): array
    {
        return array_filter(
            self::all(),
            fn (SettingDefinition $d) => $d->scope === $scope && ($group === null || $d->group === $group),
        );
    }

    /** @return array<string, SettingDefinition> */
    private static function build(): array
    {
        $p = 'platform';
        $o = 'organization';
        $T = SettingDefinition::class;

        $definitions = [
            // ── Platform: identity & support ────────────────────────────────
            new SettingDefinition('platform.name', $p, $T::TYPE_STRING, 'WellNest', 'general', 'Platform name'),
            new SettingDefinition('platform.support_email', $p, $T::TYPE_EMAIL, null, 'general', 'Support email', nullable: true),
            new SettingDefinition('platform.support_phone', $p, $T::TYPE_STRING, null, 'general', 'Support phone', nullable: true),
            new SettingDefinition('platform.announcement', $p, $T::TYPE_TEXT, null, 'general', 'Announcement banner',
                help: 'Shown at the top of every staff screen while set. Never include client information.', nullable: true),
            new SettingDefinition('platform.announcement_level', $p, $T::TYPE_ENUM, 'info', 'general', 'Announcement style',
                options: ['info' => 'Information', 'warning' => 'Warning']),

            // ── Platform: regional defaults for new organizations ───────────
            new SettingDefinition('platform.default_country', $p, $T::TYPE_STRING, 'GH', 'defaults', 'Default country',
                help: 'ISO 3166-1 alpha-2 code.', rules: ['size:2', 'alpha']),
            new SettingDefinition('platform.default_timezone', $p, $T::TYPE_TIMEZONE, 'Africa/Accra', 'defaults', 'Default timezone'),
            new SettingDefinition('platform.default_currency', $p, $T::TYPE_STRING, 'GHS', 'defaults', 'Default currency',
                help: 'ISO 4217 code.', rules: ['size:3', 'alpha']),
            new SettingDefinition('platform.default_locale', $p, $T::TYPE_ENUM, 'en', 'defaults', 'Default language', options: ['en' => 'English']),

            // ── Platform: registration ──────────────────────────────────────
            new SettingDefinition('registration.mode', $p, $T::TYPE_ENUM, 'open', 'registration', 'Registration',
                help: 'Approval mode creates new organizations as Pending until a platform administrator activates them.',
                options: ['open' => 'Open — new organizations start a trial', 'approval' => 'Requires approval', 'closed' => 'Closed']),
            new SettingDefinition('registration.default_plan', $p, $T::TYPE_STRING, 'starter', 'registration', 'Plan for new organizations',
                help: 'Plan key assigned (as a trial) to self-registered organizations.'),

            // ── Platform: legal ─────────────────────────────────────────────
            new SettingDefinition('legal.terms_url', $p, $T::TYPE_URL, null, 'legal', 'Terms of service URL', nullable: true),
            new SettingDefinition('legal.privacy_url', $p, $T::TYPE_URL, null, 'legal', 'Privacy policy URL', nullable: true),

            // ── Platform: kill switches ─────────────────────────────────────
            new SettingDefinition('platform.disabled_features', $p, $T::TYPE_LIST, [], 'features', 'Globally disabled modules',
                help: 'Turns a module off for every organization regardless of plan (emergency kill switch).',
                options: FeatureRegistry::booleanOptions()),

            // ── Organization: regional formats ──────────────────────────────
            new SettingDefinition('general.date_format', $o, $T::TYPE_ENUM, 'd/m/Y', 'general', 'Date format',
                options: ['d/m/Y' => 'DD/MM/YYYY', 'm/d/Y' => 'MM/DD/YYYY', 'Y-m-d' => 'YYYY-MM-DD', 'j M Y' => 'D MMM YYYY', 'M j, Y' => 'MMM D, YYYY']),
            new SettingDefinition('general.time_format', $o, $T::TYPE_ENUM, 'H:i', 'general', 'Time format',
                options: ['H:i' => '24-hour (14:30)', 'g:i A' => '12-hour (2:30 PM)']),
            new SettingDefinition('general.week_starts_on', $o, $T::TYPE_ENUM, '1', 'general', 'Week starts on',
                options: ['1' => 'Monday', '7' => 'Sunday']),

            // ── Organization: branding ──────────────────────────────────────
            new SettingDefinition('branding.primary_color', $o, $T::TYPE_COLOR, '#2563EB', 'branding', 'Primary color'),
            new SettingDefinition('branding.secondary_color', $o, $T::TYPE_COLOR, '#93C5FD', 'branding', 'Secondary color'),

            // ── Organization: scheduling ────────────────────────────────────
            new SettingDefinition('scheduling.default_duration_minutes', $o, $T::TYPE_INT, 50, 'scheduling', 'Default appointment length (minutes)',
                help: 'Pre-filled when creating a service.', min: 5, max: 480),
            new SettingDefinition('scheduling.slot_interval_minutes', $o, $T::TYPE_ENUM, '15', 'scheduling', 'Booking slot interval',
                help: 'Start times offered for booking are aligned to this interval.',
                options: ['5' => '5 minutes', '10' => '10 minutes', '15' => '15 minutes', '20' => '20 minutes', '30' => '30 minutes', '60' => '60 minutes']),
            new SettingDefinition('scheduling.min_notice_hours', $o, $T::TYPE_INT, 24, 'scheduling', 'Minimum booking notice (hours)',
                help: 'Clients cannot book online later than this before the start time. Staff are not limited.', min: 0, max: 720),
            new SettingDefinition('scheduling.max_advance_days', $o, $T::TYPE_INT, 60, 'scheduling', 'Booking window (days ahead)',
                help: 'How far in advance clients can book online.', min: 1, max: 365),
            new SettingDefinition('scheduling.cancellation_notice_hours', $o, $T::TYPE_INT, 24, 'scheduling', 'Late-cancellation threshold (hours)',
                help: 'Client cancellations inside this window are recorded as late. A service can override it.', min: 0, max: 168),
            new SettingDefinition('scheduling.allow_overbooking', $o, $T::TYPE_BOOL, true, 'scheduling', 'Allow staff to double-book',
                help: 'Staff with the overbooking permission may knowingly book over an existing appointment. Online booking never can.'),
            new SettingDefinition('scheduling.calendar_day_start', $o, $T::TYPE_TIME, '07:00', 'scheduling', 'Calendar shows from'),
            new SettingDefinition('scheduling.calendar_day_end', $o, $T::TYPE_TIME, '20:00', 'scheduling', 'Calendar shows until'),

            // ── Organization: clients ───────────────────────────────────────
            new SettingDefinition('clients.require_date_of_birth', $o, $T::TYPE_BOOL, false, 'clients', 'Require date of birth for new clients'),
            new SettingDefinition('clients.require_contact', $o, $T::TYPE_BOOL, true, 'clients', 'Require an email or phone number for new clients'),

            // ── Organization: telehealth ────────────────────────────────────
            new SettingDefinition('telehealth.default_link_secret', $o, $T::TYPE_SECRET, null, 'telehealth', 'Default meeting link',
                help: 'An https Zoom, Google Meet or Microsoft Teams link used for telehealth appointments that have none of their own. Stored encrypted; WellNest only shows it to staff who may join the session. Everyone who has the link enters the same room, so switch on the video service\'s waiting room.', nullable: true),
            new SettingDefinition('telehealth.allowed_hosts', $o, $T::TYPE_TEXT, "zoom.us\n*.zoom.us\nmeet.google.com\nteams.microsoft.com", 'telehealth', 'Allowed meeting-link hosts',
                help: 'One host per line; *.example.com allows its sub-domains. A meeting link on any other host is refused.'),
            new SettingDefinition('telehealth.join_early_minutes', $o, $T::TYPE_INT, 15, 'telehealth', 'Join opens (minutes before the start)',
                help: 'Staff can join a session from this long before it starts until it ends.', min: 0, max: 120),
            new SettingDefinition('telehealth.recording_enabled', $o, $T::TYPE_BOOL, false, 'telehealth', 'Allow session recordings',
                help: 'Off by default. Even when on, nothing is recorded or stored unless the client\'s consent is recorded for that session.'),
            new SettingDefinition('telehealth.ai_transcripts_enabled', $o, $T::TYPE_BOOL, false, 'telehealth', 'Allow AI transcripts',
                help: 'Off by default. An AI transcript is always a draft until a clinician reviews it. Turning this on needs an AI vendor agreement first.'),
        ];

        $map = [];
        foreach ($definitions as $definition) {
            $map[$definition->key] = $definition;
        }

        return $map;
    }
}
