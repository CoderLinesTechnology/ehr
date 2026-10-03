<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\GroupSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The scheduling and client settings forms. The group ("scheduling" / "clients") is a route default,
 * the fields are generated from SettingsRegistry, so a new setting appears here with no further work.
 */
final class GroupSettingsController extends Controller
{
    private const TITLES = ['scheduling' => 'Scheduling', 'clients' => 'Client Settings'];

    public function edit(string $group, SettingsService $settings): View
    {
        $definitions = SettingsRegistry::forScope('organization', $group);
        abort_if($definitions === [], 404);

        $organization = tenant()->organizationOrFail();
        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[GroupSettingsRequest::settingField($key)] = $settings->organization($organization, $key);
        }

        return view('app.settings.group.edit', [
            'group' => $group,
            'definitions' => $definitions,
            'values' => $values,
            'formTitle' => self::TITLES[$group] ?? ucfirst($group),
        ]);
    }

    public function update(GroupSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->setOrganization(tenant()->organizationOrFail(), $request->values(), $request->user());

        return redirect()->route($request->route()->getName() === 'app.settings.scheduling.update' ? 'app.settings.scheduling.edit' : 'app.settings.clients.edit')
            ->with('success', 'Your settings were saved.');
    }
}
