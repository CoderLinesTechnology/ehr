<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\UpdatePlatformSettings;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingsRegistry;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateSettingsRequest;
use App\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Platform settings, generated from SettingsRegistry: the form, the control
 * for each setting and its validation all come from the definition, so a new
 * setting appears here by being declared once. Secrets are write-only: the
 * page says whether one is set and never contains its value.
 */
final class SettingsController extends Controller
{
    private const GROUP_TITLES = [
        'general' => ['Platform', 'Name, support contact and the announcement banner shown on every staff screen.'],
        'defaults' => ['Defaults for new organizations', 'Regional defaults offered when an organization is created.'],
        'registration' => ['Registration', 'Who can create an organization, and on which plan.'],
        'legal' => ['Legal links', 'Shown in the footer of sign-in and registration screens.'],
        'features' => ['Kill switches', 'Emergency switches that apply to every organization regardless of plan.'],
    ];

    public function edit(SettingsService $settings): View
    {
        Gate::authorize('platform.settings.manage');

        $groups = [];
        foreach (SettingsRegistry::forScope('platform') as $key => $definition) {
            $groups[$definition->group][] = [
                'definition' => $definition,
                'field' => UpdateSettingsRequest::fieldName($key),
                // A secret's value never reaches the page: only whether one is stored.
                'value' => $definition->isSecret() ? null : $settings->platform($key),
                'secretIsSet' => $definition->isSecret() ? $settings->platformSecretIsSet($key) : false,
            ];
        }

        $titled = [];
        foreach ($groups as $group => $items) {
            $titled[] = [
                'title' => self::GROUP_TITLES[$group][0] ?? ucfirst($group),
                'description' => self::GROUP_TITLES[$group][1] ?? null,
                'items' => $items,
            ];
        }

        return view('platform.settings.edit', [
            'groups' => $titled,
            'timezones' => Regions::timezones(),
            'types' => SettingDefinition::class,
        ]);
    }

    public function update(UpdateSettingsRequest $request, UpdatePlatformSettings $update): RedirectResponse
    {
        Gate::authorize('platform.settings.manage');

        $changed = $update($request->settingValues(), $request->validated('reason'), $request->user());

        return redirect()->route('platform.settings.edit')->with(
            $changed === [] ? 'info' : 'success',
            $changed === [] ? 'Nothing changed.' : 'Platform settings were saved.',
        );
    }
}
