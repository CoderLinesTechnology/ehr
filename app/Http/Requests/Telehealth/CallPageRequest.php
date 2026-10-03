<?php

namespace App\Http\Requests\Telehealth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The call page. Its only input is `?app=`: the page the user was browsing in the call page's app frame while the
 * call floated, mirrored into the URL so a refresh restores it. It is printed into the page and loaded in a frame,
 * so anything but a plain path inside this organization's staff application is ignored (never an error).
 */
final class CallPageRequest extends FormRequest
{
    public const APP_PATH_MAX = 512;

    public function authorize(): bool
    {
        return true; // the route's `can:join,session`
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** `?app=` when it is a relative path inside /o/{$organizationSlug}, otherwise null. */
    public function appPath(string $organizationSlug): ?string
    {
        $path = $this->query('app');
        if (! is_string($path) || $path === '' || strlen($path) > self::APP_PATH_MAX || $organizationSlug === '') {
            return null;
        }

        // URL characters only (a browser percent-encodes everything else): no spaces, no control characters such as
        // CR/LF, no backslash, no quotes or angle brackets, no fragment.
        if (preg_match('~\A[A-Za-z0-9\-._\~!$&\'()*+,;=:@%/?]+\z~', $path) !== 1) {
            return null;
        }

        // Inside this organization's app: "/o/{slug}", "/o/{slug}/…" or "/o/{slug}?…" — so never a scheme, a host,
        // another organization or another part of the site.
        $base = '/o/'.$organizationSlug;
        if ($path !== $base && ! str_starts_with($path, $base.'/') && ! str_starts_with($path, $base.'?')) {
            return null;
        }

        // No "//" (a protocol-relative host once a browser or proxy normalises the path), no dot segments, and no
        // percent-encoded slash, backslash, dot or control character that could be decoded into one of those.
        $route = explode('?', $path, 2)[0];
        if (str_contains($path, '//')
            || preg_match('~(?:\A|/)\.{1,2}(?:/|\z)~', $route) === 1
            || preg_match('~%(?:2e|2f|5c)~i', $route) === 1
            || preg_match('~%(?:[01][0-9a-f]|7f)~i', $path) === 1) {
            return null;
        }

        return $path;
    }
}
