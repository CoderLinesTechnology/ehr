<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\PlatformAbility;
use App\Domain\Saas\ChangeSubscriptionPlan;
use App\Domain\Saas\ChangeSubscriptionStatus;
use App\Domain\Saas\StartOrganizationSubscription;
use App\Domain\Saas\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use App\Http\Requests\Platform\ChangeSubscriptionPlanRequest;
use App\Http\Requests\Platform\ChangeSubscriptionStatusRequest;
use App\Http\Requests\Platform\StartSubscriptionRequest;
use App\Models\Organization;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;

/** An organization's subscription: start one, move it to another plan, change its status. */
final class OrganizationSubscriptionController extends Controller
{
    use AuthorizesPlatform;

    public function start(StartSubscriptionRequest $request, Organization $organization, StartOrganizationSubscription $start): RedirectResponse
    {
        $this->allow(PlatformAbility::StartSubscription);

        $plan = Plan::query()->findOrFail($request->validated('plan_id'));
        $start($organization, $plan, $request->boolean('trial'), $request->user(), $request->validated('reason'));

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "A subscription on the {$plan->name} plan was started.");
    }

    public function changePlan(ChangeSubscriptionPlanRequest $request, Organization $organization, ChangeSubscriptionPlan $change): RedirectResponse
    {
        $this->allow(PlatformAbility::ChangeSubscriptionPlan);

        $plan = Plan::query()->findOrFail($request->validated('plan_id'));
        $change($organization, $plan, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "{$organization->name} is now on the {$plan->name} plan.");
    }

    public function changeStatus(ChangeSubscriptionStatusRequest $request, Organization $organization, ChangeSubscriptionStatus $change): RedirectResponse
    {
        $this->allow(PlatformAbility::ChangeSubscriptionStatus);

        $to = SubscriptionStatus::from($request->validated('status'));
        $subscription = $change($organization, $to, $request->user(), $request->validated('reason'), $request->graceEndsAt());

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "The subscription is now {$subscription->status->label()}.");
    }
}
