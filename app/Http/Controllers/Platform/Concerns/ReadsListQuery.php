<?php

namespace App\Http\Controllers\Platform\Concerns;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Safe reading of list-screen query strings (search, filters, sort, dates).
 * Anything unexpected is quietly ignored rather than rejected, so a mangled
 * link shows the unfiltered list instead of an error. Sort columns are always
 * picked from a whitelist the controller supplies, never taken from the request.
 */
trait ReadsListQuery
{
    protected function queryText(Request $request, string $key, int $max = 100): string
    {
        $value = $request->query($key);

        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /** @param list<string> $allowed */
    protected function queryChoice(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @param list<string> $allowed */
    protected function sortColumn(Request $request, array $allowed, string $default): string
    {
        return $this->queryChoice($request, 'sort', $allowed) ?? $default;
    }

    protected function sortDirection(Request $request, string $default): string
    {
        return $this->queryChoice($request, 'direction', ['asc', 'desc']) ?? $default;
    }

    /** `%term%` for LIKE/ILIKE, with the wildcard characters in the term itself escaped. */
    protected function containsPattern(string $term): string
    {
        return '%'.$this->escapeLike($term).'%';
    }

    /** `term%` for LIKE/ILIKE. */
    protected function prefixPattern(string $term): string
    {
        return $this->escapeLike($term).'%';
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /** The viewer's own timezone (users.timezone), or the application's. */
    protected function viewerTimezone(Request $request): string
    {
        $timezone = $request->user()?->timezone;

        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : (string) config('app.timezone');
    }

    /**
     * A `Y-m-d` query value as the START of that day in the viewer's timezone,
     * returned in UTC: the database session speaks UTC and Laravel writes bound
     * instants without an offset, so an instant in any other zone would be
     * read as the wrong moment.
     */
    protected function queryDate(Request $request, string $key): ?CarbonImmutable
    {
        $value = $request->query($key);
        if (! is_string($value) || ! CarbonImmutable::hasFormat($value, 'Y-m-d')) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->viewerTimezone($request));

        return $date === false ? null : $date->startOfDay()->utc();
    }
}
