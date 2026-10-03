<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Saas\FeatureRegistry;
use App\Domain\Saas\SavePlan;
use App\Domain\Saas\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SavePlanRequest;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Support\Money;
use App\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Plans and their feature matrix. Plans are never deleted: deactivate instead. */
final class PlanController extends Controller
{
    public function index(): View
    {
        Gate::authorize('platform.plans.manage');

        return view('platform.plans.index', [
            'plans' => Plan::query()
                ->withCount(['subscriptions as live_subscriptions_count' => fn ($query) => $query->whereIn('status', SubscriptionStatus::LIVE)])
                ->orderBy('sort')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('platform.plans.manage');

        return view('platform.plans.form', $this->formData(null, [
            'currency' => 'GHS', 'billing_interval' => 'month', 'trial_days' => 14, 'sort' => 10, 'is_public' => true, 'is_active' => true,
        ], [], 0));
    }

    public function store(SavePlanRequest $request, SavePlan $save): RedirectResponse
    {
        Gate::authorize('platform.plans.manage');

        $plan = $save(null, $request->planAttributes(), $request->featureMatrix(), $request->limitMatrix(), $request->user());

        return redirect()->route('platform.plans.edit', $plan)->with('success', "The {$plan->name} plan was created.");
    }

    public function edit(Plan $plan): View
    {
        Gate::authorize('platform.plans.manage');

        $rows = PlanFeature::query()->where('plan_id', $plan->id)->get()->keyBy('feature_key');

        return view('platform.plans.form', $this->formData($plan, [
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => Money::toDecimalString($plan->price_minor, $plan->currency),
            'currency' => $plan->currency,
            'billing_interval' => $plan->billing_interval,
            'trial_days' => $plan->trial_days,
            'sort' => $plan->sort,
            'is_public' => $plan->is_public,
            'is_active' => $plan->is_active,
        ], $rows->all(), $plan->subscriptions()->whereIn('status', SubscriptionStatus::LIVE)->count()));
    }

    public function update(SavePlanRequest $request, Plan $plan, SavePlan $save): RedirectResponse
    {
        Gate::authorize('platform.plans.manage');

        $plan = $save($plan, $request->planAttributes(), $request->featureMatrix(), $request->limitMatrix(), $request->user());

        return redirect()->route('platform.plans.edit', $plan)->with('success', "The {$plan->name} plan was saved.");
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, PlanFeature>  $rows
     * @return array<string, mixed>
     */
    private function formData(?Plan $plan, array $values, array $rows, int $liveSubscriptions): array
    {
        return [
            'plan' => $plan,
            'values' => $values,
            'rows' => $rows,
            'liveSubscriptions' => $liveSubscriptions,
            'features' => FeatureRegistry::definitions(),
            'currencies' => Regions::currencies(),
        ];
    }
}
