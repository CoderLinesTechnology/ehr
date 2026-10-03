<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\PlatformAbility;
use App\Domain\Platform\ProvisionOrganization;
use App\Domain\Platform\Queries\OrganizationOverview;
use App\Domain\Platform\Queries\PlatformOrganizationQuery;
use App\Domain\Platform\UpdateOrganizationProfile;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use App\Http\Requests\Platform\StoreOrganizationRequest;
use App\Http\Requests\Platform\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Support\Regions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * Organizations as the platform sees them: profile, lifecycle, plan, entitlements and usage
 * COUNTS. Never a client, an appointment or any other tenant record. Reads go through
 * PlatformOrganizationQuery / OrganizationOverview; rules live in the domain actions.
 */
final class OrganizationController extends Controller
{
    use AuthorizesPlatform;

    public function index(Request $request, PlatformOrganizationQuery $query): View
    {
        $this->allow(PlatformAbility::ViewOrganizations);

        $filters = $request->only(['q', 'status', 'plan', 'sort', 'direction']);

        return view('platform.organizations.index', [
            'organizations' => $query->paginate($filters)->withQueryString(),
            'plans' => Plan::query()->orderBy('sort')->orderBy('name')->get(['key', 'name']),
            'statuses' => OrganizationStatus::cases(),
            'filters' => [
                'q' => is_string($filters['q'] ?? null) ? $filters['q'] : '',
                'status' => $filters['status'] ?? null,
                'plan' => $filters['plan'] ?? null,
            ],
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function create(SettingsService $settings): View
    {
        $this->allow(PlatformAbility::CreateOrganization);

        return view('platform.organizations.create', [
            'plans' => $this->activePlans(),
            'defaults' => [
                'country_code' => $settings->platform('platform.default_country'),
                'timezone' => $settings->platform('platform.default_timezone'),
                'currency' => $settings->platform('platform.default_currency'),
            ],
            'countries' => Regions::countries(),
            'currencies' => Regions::currencies(),
            'timezones' => Regions::timezones(),
        ]);
    }

    public function store(StoreOrganizationRequest $request, ProvisionOrganization $provision): RedirectResponse
    {
        $this->allow(PlatformAbility::CreateOrganization);

        $data = $request->validated();

        $provisioned = $provision(
            profile: Arr::only($data, ['name', 'slug', 'legal_name', 'country_code', 'timezone', 'currency', 'email', 'phone']),
            plan: Plan::query()->findOrFail($data['plan_id']),
            status: OrganizationStatus::from($data['status']),
            ownerEmail: $data['owner_email'],
            actor: $request->user(),
        );

        $organization = $provisioned->created->organization;

        return redirect()->route('platform.organizations.show', $organization)->with(
            $provisioned->invitationSent ? 'success' : 'warning',
            $provisioned->invitationSent
                ? "{$organization->name} was created and an invitation was emailed to {$data['owner_email']}."
                : "{$organization->name} was created, but the invitation email could not be sent. Check the mail settings and failed jobs, then contact the owner another way.",
        );
    }

    public function show(Request $request, Organization $organization, OrganizationOverview $overview): View
    {
        $this->allow(PlatformAbility::ViewOrganizations);

        return view('platform.organizations.show', [
            'overview' => $overview($organization),
            'plans' => $this->activePlans(),
            'features' => collect(FeatureRegistry::definitions())->keyBy('key'),
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function edit(Organization $organization): View
    {
        $this->allow(PlatformAbility::UpdateOrganization);

        return view('platform.organizations.edit', [
            'organization' => $organization,
            'countries' => Regions::countries(),
            'currencies' => Regions::currencies(),
            'timezones' => Regions::timezones(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganizationProfile $update): RedirectResponse
    {
        $this->allow(PlatformAbility::UpdateOrganization);

        $update($organization, $request->validated());

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "{$organization->name} was updated.");
    }

    /** @return Collection<int, Plan> */
    private function activePlans()
    {
        return Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('name')
            ->get(['id', 'key', 'name', 'price_minor', 'currency', 'billing_interval', 'trial_days']);
    }
}
