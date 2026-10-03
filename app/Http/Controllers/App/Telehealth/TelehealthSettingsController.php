<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Settings\SettingsService;
use App\Domain\Telehealth\Providers\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\TelehealthSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Organization telehealth settings (permission `telehealth.manage`): join window, recording, AI transcripts, and
 * the video service's status. The video service itself is code configuration (the server's environment), shown
 * here read-only.
 */
final class TelehealthSettingsController extends Controller
{
    public function edit(SettingsService $settings, ProviderRegistry $providers): View
    {
        $organization = tenant()->organizationOrFail();
        $provider = $providers->default();

        return view('app.settings.telehealth.edit', [
            'values' => [
                'join_early_minutes' => (int) $settings->organization($organization, 'telehealth.join_early_minutes'),
                'recording_enabled' => (bool) $settings->organization($organization, 'telehealth.recording_enabled'),
                'ai_transcripts_enabled' => (bool) $settings->organization($organization, 'telehealth.ai_transcripts_enabled'),
            ],
            'videoService' => $provider->label(),
            'videoStatus' => $provider->status(),
        ]);
    }

    public function update(TelehealthSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->setOrganization(tenant()->organizationOrFail(), $request->values(), $request->user());

        return redirect()->route('app.settings.telehealth.edit')->with('success', 'Your settings were saved.');
    }
}
