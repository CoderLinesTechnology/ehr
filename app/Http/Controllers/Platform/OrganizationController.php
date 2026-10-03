<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\OrganizationUsage;
use App\Domain\Platform\ProvisionOrganization;
use App\Domain\Platform\UpdateOrganizationProfile;
use App\Domain\Saas\EntitlementReport;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\SubscriptionTransitions;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\ReadsListQuery;
use App\Http\Requests\Platform\StoreOrganizationRequest;
use App\Http\Requests\Platform\UpdateOrganizationRequest;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SubscriptionHistory;
use App\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Organizations as the platform sees them: profile, lifecycle, plan and usage
 * COUNTS. Never a client, an appointment or any other tenant record.
 */
final class OrganizationController extends Controller
{
    use ReadsListQuery;

    private const SORTS = ['name', 'created_at'];

    private const PER_PAGE = 25;

    public function index(Request $request, OrganizationUsage $usage): View
    {
        Gate::authorize('platform.organizations.view');

        $term = $this->queryText($request, 'q');
        $status = $this->queryChoice($request, 'status', OrganizationStatus::values());
        $plans = Plan::query()->orderBy('sort')->orderBy('name')->get(['id', 'key', 'name']);
        $planKey = $this->queryChoice($request, 'plan', [...$plans->pluck('key')->all(), 'none']);
        $sort = $this->sortColumn($request, self::SORTS, 'created_at');
        $direction = $this->sortDirection($request, $sort === 'name' ? 'asc' : 'desc');

        $organizations = Organization::query()
            // Only what the list shows: the page never needs the rest of the row.
            ->select(['id', 'slug', 'name', 'status', 'email', 'created_at'])
            ->with(['liveSubscription' => fn ($query) => $query
                ->select(['id', 'organization_id', 'plan_id', 'status'])
                ->with('plan:id,key,name')])
            ->when($term !== '', function ($query) use ($term) {
                $like = $this->containsPattern($term);
                $query->where(fn ($match) => $match
                    ->where('name', 'ilike', $like)
                    ->orWhere('slug', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($planKey === 'none', fn ($query) => $query->whereDoesntHave('liveSubscription'))
            ->when($planKey !== null && $planKey !== 'none', function ($query) use ($plans, $planKey) {
                $planId = $plans->firstWhere('key', $planKey)?->id;
                $query->whereHas('liveSubscription', fn ($subscription) => $subscription->where('plan_id', $planId));
            })
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('platform.organizations.index', [
            'organizations' => $organizations,
            'staffCounts' => $usage->staffCounts($organizations->pluck('id')->all()),
            'plans' => $plans,
            'filters' => ['q' => $term, 'status' => $status, 'plan' => $planKey],
            'statuses' => OrganizationStatus::cases(),
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function create(SettingsService $settings): View
    {
        Gate::authorize('platform.organizations.manage');

        return view('platform.organizations.create', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'key', 'name', 'price_minor', 'currency', 'billing_interval', 'trial_days']),
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
        Gate::authorize('platform.organizations.manage');

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

    public function show(
        Organization $organization,
        OrganizationUsage $usage,
        EntitlementReport $entitlements,
        EntitlementService $entitlementService,
        Request $request,
    ): View {
        Gate::authorize('platform.organizations.view');

        $organization->load(['liveSubscription.plan']);
        $subscription = $organization->liveSubscription;

        $statusHistory = $organization->statusHistory()->with('actor:id,name')->limit(100)->get();

        $subscriptionHistory = SubscriptionHistory::query()
            ->where('organization_id', $organization->id)
            ->with(['fromPlan:id,name', 'toPlan:id,name', 'actor:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get();

        $limits = [];
        foreach ([FeatureRegistry::MAX_STAFF => 'staff', FeatureRegistry::MAX_ACTIVE_CLIENTS => 'clients', FeatureRegistry::MAX_LOCATIONS => 'locations'] as $key => $usageKey) {
            $limits[$usageKey] = $entitlementService->limit($organization, $key);
        }

        return view('platform.organizations.show', [
            'organization' => $organization,
            'subscription' => $subscription,
            'statusHistory' => $statusHistory,
            'subscriptionHistory' => $subscriptionHistory,
            'entitlements' => $entitlements($organization),
            'usage' => $usage($organization),
            'limits' => $limits,
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'key', 'name', 'price_minor', 'currency', 'billing_interval', 'trial_days']),
            'audit' => AuditLog::query()
                ->platformVisible()
                ->where('organization_id', $organization->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'statusTransitions' => $organization->status->allowedTransitions(),
            'subscriptionTransitions' => $subscription !== null ? SubscriptionTransitions::allowed($subscription->status) : [],
            'timezone' => $request->user()->timezone,
        ]);
    }

    public function edit(Organization $organization): View
    {
        Gate::authorize('platform.organizations.manage');

        return view('platform.organizations.edit', [
            'organization' => $organization,
            'countries' => Regions::countries(),
            'currencies' => Regions::currencies(),
            'timezones' => Regions::timezones(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganizationProfile $update): RedirectResponse
    {
        Gate::authorize('platform.organizations.manage');

        $update($organization, $request->validated());

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "{$organization->name} was updated.");
    }
}
