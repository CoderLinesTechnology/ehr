<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ChangeOrganizationStatusRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** Lifecycle changes (suspend, archive, cancel, reactivate): all rules live in ChangeOrganizationStatus. */
final class OrganizationStatusController extends Controller
{
    public function __invoke(ChangeOrganizationStatusRequest $request, Organization $organization, ChangeOrganizationStatus $change): RedirectResponse
    {
        Gate::authorize('platform.organizations.lifecycle');

        $to = OrganizationStatus::from($request->validated('status'));
        $change($organization, $to, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "{$organization->name} is now {$organization->status->label()}.");
    }
}
