<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Resolves Lucide icons from resources/icons/lucide/<name>.svg into inline SVG.
 *
 * The name is validated against ^[a-z0-9-]+$ before it ever touches the filesystem (no path traversal), the inner
 * markup of each file is cached for the life of the process, and an unknown name renders nothing (and logs a
 * warning outside production) instead of breaking the page.
 */
final class Icons
{
    /** Names the first (hand-drawn) icon set used that Lucide spells differently. */
    private const ALIASES = ['message' => 'message-circle', 'flask' => 'flask-conical', 'refresh' => 'refresh-cw'];

    /** @var array<string, string|null> inner SVG markup per icon, null = unknown */
    private static array $cache = [];

    public static function exists(string $name): bool
    {
        return self::inner($name) !== null;
    }

    /** Inner markup (paths, circles…) of the icon, or null when the name is invalid or unknown. */
    public static function inner(string $name): ?string
    {
        $name = self::ALIASES[$name] ?? $name;

        // An invalid name never touches the filesystem and is never cached (no path traversal, no unbounded cache).
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            self::warn($name);

            return null;
        }

        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        $markup = null;
        $file = resource_path('icons/lucide/'.$name.'.svg');
        if (is_file($file) && preg_match('/<svg\b[^>]*>(.*)<\/svg>/s', (string) file_get_contents($file), $m) === 1) {
            $markup = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
        }

        if ($markup === null) {
            self::warn($name);
        }

        return self::$cache[$name] = $markup;
    }

    private static function warn(string $name): void
    {
        if (app()->environment('local', 'testing')) {
            Log::warning('Unknown icon requested', ['icon' => mb_substr($name, 0, 64)]);
        }
    }

    /** Complete <svg> element, or an empty string for an unknown icon. $label makes it an image with an accessible name. */
    public static function svg(string $name, int|float $size = 20, ?string $label = null, int|float $stroke = 1.75, string $class = ''): string
    {
        $inner = self::inner($name);
        if ($inner === null) {
            return '';
        }

        $size = max(8, min(256, (float) $size));
        $a11y = filled($label) ? 'role="img" aria-label="'.e($label).'"' : 'aria-hidden="true" focusable="false"';

        return sprintf(
            '<svg class="icon%s" xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" %s>%s</svg>',
            $class !== '' ? ' '.e($class) : '',
            rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format((float) $stroke, 2, '.', ''), '0'), '.'),
            $a11y,
            $inner,
        );
    }
}
