<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Organization\UpdateOrganizationLogo;
use App\Domain\Organization\UpdateOrganizationProfile;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Support\Regions;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Settings → Organization: profile, general information, branding and contact details. */
final class OrganizationController extends Controller
{
    public function edit(SettingsService $settings): View
    {
        $organization = tenant()->organizationOrFail();

        return view('app.settings.organization.edit', [
            'organization' => $organization,
            'values' => [
                'date_format' => $settings->organization($organization, 'general.date_format'),
                'time_format' => $settings->organization($organization, 'general.time_format'),
                'week_starts_on' => $settings->organization($organization, 'general.week_starts_on'),
                'primary_color' => $settings->organization($organization, 'branding.primary_color'),
                'secondary_color' => $settings->organization($organization, 'branding.secondary_color'),
            ],
            'timezones' => self::timezoneOptions(),
            'currencies' => self::currencyOptions(),
            'dateFormats' => SettingsRegistry::get('general.date_format')->options,
            'timeFormats' => SettingsRegistry::get('general.time_format')->options,
            'weekStarts' => SettingsRegistry::get('general.week_starts_on')->options,
            'locales' => SettingsRegistry::get('platform.default_locale')->options,
            'countries' => Regions::countries(),
            'logoUrl' => self::logoUrl($organization),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, UpdateOrganizationProfile $update): RedirectResponse
    {
        $organization = tenant()->organizationOrFail();

        $changed = $update($organization, $request->profile($organization), $request->formats());

        $message = 'Your organization settings were saved.';
        if (array_intersect($changed, ['timezone', 'currency'])) {
            $message .= ' Existing appointments and services keep the timezone and currency they were created with.';
        }

        return redirect()->route('app.settings.organization.edit')->with('success', $message);
    }

    public function storeLogo(Request $request, UpdateOrganizationLogo $update): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'file']], ['logo.required' => 'Choose an image to upload.', 'logo.file' => 'Choose an image to upload.']);

        $update(tenant()->organizationOrFail(), $request->file('logo'));

        return redirect()->route('app.settings.organization.edit')->with('success', 'The logo was updated.');
    }

    /** Only members of this organization reach here (tenant middleware); the path comes from the row, never the URL. */
    public function logo(): Response
    {
        $organization = tenant()->organizationOrFail();
        $path = (string) $organization->logo_path;

        abort_unless($path !== '' && str_starts_with($path, "organizations/{$organization->id}/") && ! str_contains($path, '..'), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'Content-Type' => str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    public static function logoUrl(Organization $organization): ?string
    {
        return $organization->logo_path
            ? route('app.settings.organization.logo', ['organization' => $organization->slug, 'v' => substr(md5($organization->logo_path), 0, 8)])
            : null;
    }

    /** @return array<string, string> identifier => "(GMT+01:00) Africa/Lagos" */
    private static function timezoneOptions(): array
    {
        $now = new \DateTimeImmutable('now', new DateTimeZone('UTC'));
        $options = [];
        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $offset = (new DateTimeZone($identifier))->getOffset($now);
            $label = $offset === 0 ? '(GMT)' : sprintf('(GMT%s%02d:%02d)', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
            $options[$identifier] = $label.' '.$identifier;
        }

        return $options;
    }

    /** @return array<string, string> "GHS — Ghanaian cedi" shown as "GHS (Ghanaian Cedi)" */
    private static function currencyOptions(): array
    {
        return array_map(function (string $label): string {
            [$code, $name] = array_pad(explode(' — ', $label, 2), 2, '');
            $name = preg_replace_callback('/\b([a-z])/u', fn ($m) => mb_strtoupper($m[1]), $name);

            return $name === '' ? $code : "{$code} ({$name})";
        }, Regions::currencies());
    }
}
