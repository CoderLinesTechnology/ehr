<?php

namespace App\Support;

use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Display formatting for the current organization: its date/time formats,
 * timezone and currency. Use through the fmt() helper in Blade.
 *
 * Instants (timestamptz) are shown in the timezone passed (an appointment's
 * own timezone) or the organization's; business dates (DATE columns) are
 * formatted as-is, never shifted.
 */
final class Formatter
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsService $settings,
    ) {}

    public function timezone(): string
    {
        return $this->tenant->organization()?->timezone ?? config('app.timezone');
    }

    public function dateFormat(): string
    {
        $organization = $this->tenant->organization();

        return $organization ? (string) $this->settings->organization($organization, 'general.date_format') : 'j M Y';
    }

    public function timeFormat(): string
    {
        $organization = $this->tenant->organization();

        return $organization ? (string) $this->settings->organization($organization, 'general.time_format') : 'H:i';
    }

    public function weekStartsOn(): int
    {
        $organization = $this->tenant->organization();

        return $organization ? (int) $this->settings->organization($organization, 'general.week_starts_on') : 1;
    }

    /** Business date (DATE column) — no timezone shift. */
    public function date(DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $date = is_string($value) ? CarbonImmutable::parse($value) : CarbonImmutable::instance($value);

        return $date->format($this->dateFormat());
    }

    /** Instant → local date in $timezone (default: organization). */
    public function localDate(?DateTimeInterface $instant, ?string $timezone = null): string
    {
        return $instant === null ? '—' : $this->local($instant, $timezone)->format($this->dateFormat());
    }

    public function time(?DateTimeInterface $instant, ?string $timezone = null): string
    {
        return $instant === null ? '—' : $this->local($instant, $timezone)->format($this->timeFormat());
    }

    public function dateTime(?DateTimeInterface $instant, ?string $timezone = null): string
    {
        return $instant === null ? '—' : $this->local($instant, $timezone)->format($this->dateFormat().' '.$this->timeFormat());
    }

    /** "Mon 6 Oct" style heading for calendars and lists. */
    public function dayHeading(DateTimeInterface $instant, ?string $timezone = null): string
    {
        return $this->local($instant, $timezone)->format('D j M');
    }

    /** Timezone abbreviation when it differs from the organization's (e.g. a location in another zone). */
    public function zoneSuffix(?string $timezone): string
    {
        if ($timezone === null || $timezone === $this->timezone()) {
            return '';
        }

        return ' '.CarbonImmutable::now($timezone)->format('T');
    }

    public function money(?int $minor, ?string $currency = null): string
    {
        if ($minor === null) {
            return '—';
        }

        $currency ??= $this->tenant->organization()?->currency ?? 'USD';

        return Money::format($minor, $currency);
    }

    public function local(DateTimeInterface $instant, ?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($timezone ?? $this->timezone());
    }
}
