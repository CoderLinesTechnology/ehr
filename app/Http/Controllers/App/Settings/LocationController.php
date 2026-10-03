<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Organization\BusinessHours;
use App\Domain\Organization\ChangeLocationStatus;
use App\Domain\Organization\SaveLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\LocationRequest;
use App\Models\Location;
use App\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Settings → Locations. Locations are never deleted (appointments reference them); they are deactivated. */
final class LocationController extends Controller
{
    public function index(): View
    {
        return view('app.settings.locations.index', [
            'locations' => Location::query()->orderByDesc('is_active')->orderBy('sort')->orderBy('name')->limit(200)->get(),
        ]);
    }

    public function create(): View
    {
        return view('app.settings.locations.form', $this->formData(null));
    }

    public function store(LocationRequest $request, SaveLocation $save): RedirectResponse
    {
        $location = $save($request->details());

        return redirect()->route('app.settings.locations.index')->with('success', "{$location->name} was added.");
    }

    public function edit(Location $location): View
    {
        return view('app.settings.locations.form', $this->formData($location));
    }

    public function update(LocationRequest $request, Location $location, SaveLocation $save): RedirectResponse
    {
        $save($request->details(), $location);

        return redirect()->route('app.settings.locations.index')->with('success', "{$location->name} was saved.");
    }

    public function status(Request $request, Location $location, ChangeLocationStatus $change): RedirectResponse
    {
        $active = $request->boolean('active');
        $change($location, $active);

        return redirect()->route('app.settings.locations.index')
            ->with('success', $active ? "{$location->name} is active again." : "{$location->name} was deactivated.");
    }

    /** @return array<string, mixed> */
    private function formData(?Location $location): array
    {
        $organization = tenant()->organizationOrFail();

        return [
            'location' => $location,
            'countries' => Regions::countries(),
            'timezones' => Regions::timezones(),
            'defaults' => ['country_code' => $organization->country_code, 'timezone' => $organization->timezone],
            'hours' => BusinessHours::toForm($location?->business_hours ?? ($location === null ? BusinessHours::suggested() : null)),
            'days' => BusinessHours::DAYS,
        ];
    }
}
