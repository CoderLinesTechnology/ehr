<?php

namespace App\Http\Controllers\Onboarding;

use App\Domain\Platform\CreateOrganization;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\User;
use App\Support\Regions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-service: a verified user with no organization creates one and becomes
 * its administrator. Registration mode decides trial (open) vs pending
 * approval; when registration is closed only platform admins create tenants.
 */
final class CreateOrganizationController extends Controller
{
    public function create(Request $request, SettingsService $settings): View|RedirectResponse
    {
        if ($request->user()->activeMemberships()->exists()) {
            return redirect()->route('home');
        }

        $mode = $settings->platform('registration.mode');

        return view('onboarding.create-organization', [
            'registrationClosed' => $mode === 'closed',
            'requiresApproval' => $mode === 'approval',
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

    public function store(Request $request, SettingsService $settings, CreateOrganization $createOrganization): RedirectResponse
    {
        $user = $request->user();
        $mode = $settings->platform('registration.mode');
        abort_if($mode === 'closed', 403, 'New organizations are created by the platform team.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'country_code' => ['required', 'string', Rule::in(array_keys(Regions::countries()))],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'currency' => ['required', 'string', Rule::in(array_keys(Regions::currencies()))],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $plan = Plan::query()->where('key', $settings->platform('registration.default_plan'))->where('is_active', true)->first()
            ?? Plan::query()->where('is_active', true)->orderBy('sort')->firstOrFail();

        // The user row is the mutex: a double-submitted form cannot create two
        // organizations (the membership check runs under the lock).
        $created = DB::transaction(function () use ($user, $data, $plan, $mode, $createOrganization) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            abort_if($user->activeMemberships()->exists(), 403, 'You already belong to an organization.');

            return $createOrganization(
                profile: $data + ['email' => $user->email],
                plan: $plan,
                status: $mode === 'approval' ? OrganizationStatus::Pending : OrganizationStatus::Trial,
                owner: $user,
            );
        });

        if ($mode === 'approval') {
            return redirect()->route('app.dashboard', ['organization' => $created->organization->slug]);
        }

        return redirect()->route('app.dashboard', ['organization' => $created->organization->slug])
            ->with('success', "Welcome to {$created->organization->name}. Let's get your practice set up.");
    }
}
