<?php

namespace App\Http\Controllers\App\Telehealth;

use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\TelehealthSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Organization telehealth settings (permission `telehealth.manage`): link hosts, default link, join window, recording, AI transcripts. */
final class TelehealthSettingsController extends Controller
{
    public function edit(SettingsService $settings): View
    {
        $organization = tenant()->organizationOrFail();
        $link = $settings->organization($organization, 'telehealth.default_link_secret');

        return view('app.settings.telehealth.edit', [
            'values' => [
                'allowed_hosts' => (string) $settings->organization($organization, 'telehealth.allowed_hosts'),
                'join_early_minutes' => (int) $settings->organization($organization, 'telehealth.join_early_minutes'),
                'recording_enabled' => (bool) $settings->organization($organization, 'telehealth.recording_enabled'),
                'ai_transcripts_enabled' => (bool) $settings->organization($organization, 'telehealth.ai_transcripts_enabled'),
            ],
            'hasDefaultLink' => is_string($link) && $link !== '',
        ]);
    }

    public function update(TelehealthSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->setOrganization(tenant()->organizationOrFail(), $request->values(), $request->user());

        return redirect()->route('app.settings.telehealth.edit')->with('success', 'Your settings were saved.');
    }
}
