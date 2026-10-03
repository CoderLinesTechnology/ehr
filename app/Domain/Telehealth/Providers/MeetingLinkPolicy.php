<?php

namespace App\Domain\Telehealth\Providers;

use App\Domain\Settings\SettingsService;
use App\Domain\Shared\DomainException;
use App\Models\Organization;

/**
 * Which meeting links WellNest accepts. A link is only ever a place the staff member's browser is sent to,
 * so the rules are about not sending them somewhere unexpected: https only, no credentials in the URL, the
 * standard port, and a host on the organization's allowlist (default zoom.us, *.zoom.us, meet.google.com,
 * teams.microsoft.com). Checked when a link is saved AND again when it is used, so tightening the allowlist
 * takes effect on links already stored.
 */
final class MeetingLinkPolicy
{
    public const MAX_LENGTH = 2048;

    public function __construct(private readonly SettingsService $settings) {}

    /** @throws DomainException */
    public function normalize(string $url, Organization $organization, string $field = 'join_url'): string
    {
        return self::check($url, $this->allowedHosts($organization), $field);
    }

    /**
     * @param  list<string>  $allowedHosts
     *
     * @throws DomainException
     */
    public static function check(string $url, array $allowedHosts, string $field = 'join_url'): string
    {
        $url = trim($url);

        // A backslash is refused anywhere: browsers read it as "/" in an https URL, parse_url does not.
        if ($url === '' || mb_strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            throw new DomainException('Enter the meeting link as one https address.', 'meeting_link_invalid', $field);
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            throw new DomainException('Meeting links must start with https://.', 'meeting_link_https', $field);
        }
        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new DomainException('That meeting link is not accepted.', 'meeting_link_invalid', $field);
        }

        // The host must be a plain DNS name before it is compared, so "%2F" or other characters parse_url leaves in
        // the host cannot put a different host in front of an allowed suffix.
        $host = rtrim(strtolower($parts['host']), '.');
        if (! self::isHostName($host) || ! self::hostAllowed($host, $allowedHosts)) {
            throw new DomainException('That video service is not on your organization\'s list of allowed meeting hosts.', 'meeting_link_host', $field);
        }

        return $url;
    }

    public function accepts(string $url, Organization $organization): bool
    {
        try {
            $this->normalize($url, $organization);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /** @return list<string> lower-case host patterns, e.g. "zoom.us", "*.zoom.us" */
    public function allowedHosts(Organization $organization): array
    {
        return self::parseHosts((string) $this->settings->organization($organization, 'telehealth.allowed_hosts'));
    }

    /** @return list<string> */
    public static function parseHosts(string $text): array
    {
        $hosts = [];
        foreach (preg_split('/[\r\n,]+/', strtolower($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && self::validPattern($line)) {
                $hosts[] = $line;
            }
        }

        return array_values(array_unique($hosts));
    }

    /** A host name, optionally with a leading "*." — never a bare "*" or a TLD-only wildcard. */
    public static function validPattern(string $pattern): bool
    {
        return preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $pattern) === 1;
    }

    /** "Zoom", "Google Meet", "Microsoft Teams", or "Video" for a host the vendor list does not know. */
    public static function vendorLabel(?string $url): string
    {
        $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';

        return match (true) {
            $host === 'zoom.us' || str_ends_with($host, '.zoom.us') => 'Zoom',
            $host === 'meet.google.com' => 'Google Meet',
            $host === 'teams.microsoft.com' || $host === 'teams.live.com' => 'Microsoft Teams',
            default => 'Video',
        };
    }

    private static function isHostName(string $host): bool
    {
        return preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    /** @param list<string> $patterns */
    private static function hostAllowed(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }
}
