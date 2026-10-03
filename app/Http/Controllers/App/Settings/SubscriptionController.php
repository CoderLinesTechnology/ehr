<?php

namespace App\Http\Controllers\App\Settings;

use App\Domain\Clients\ActiveClientLimit;
use App\Domain\Identity\StaffSeats;
use App\Domain\Saas\EntitlementService;
use App\Domain\Saas\FeatureRegistry;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Location;
use Illuminate\View\View;

/** Settings → Subscription & usage: the plan, and each limit against how much is used. Read-only (plans change on the platform side). */
final class SubscriptionController extends Controller
{
    public function show(EntitlementService $entitlements, StaffSeats $seats, ActiveClientLimit $clients): View
    {
        $organization = tenant()->organizationOrFail();
        $subscription = $organization->liveSubscription()->with('plan:id,name,description,billing_interval')->first();

        $usage = [
            FeatureRegistry::MAX_STAFF => $seats->inUse(),
            FeatureRegistry::MAX_ACTIVE_CLIENTS => $clients->current(),
            FeatureRegistry::MAX_LOCATIONS => Location::query()->active()->count(),
        ];

        $limits = [];
        foreach (FeatureRegistry::definitions() as $definition) {
            if ($definition['type'] !== Feature::TYPE_LIMIT || ! array_key_exists($definition['key'], $usage)) {
                continue;
            }
            $limit = $entitlements->limit($organization, $definition['key']);
            $used = $usage[$definition['key']];
            $limits[] = [
                'name' => $definition['name'], 'description' => $definition['description'], 'used' => $used, 'limit' => $limit,
                'percent' => $limit === null || $limit === 0 ? ($limit === 0 ? 100 : 0) : min(100, (int) round($used / $limit * 100)),
            ];
        }

        $modules = [];
        foreach (FeatureRegistry::definitions() as $definition) {
            if ($definition['type'] !== Feature::TYPE_LIMIT) {
                $modules[] = ['name' => $definition['name'], 'on' => $entitlements->allows($organization, $definition['key'])];
            }
        }

        return view('app.settings.subscription.show', compact('subscription', 'limits', 'modules'));
    }
}
