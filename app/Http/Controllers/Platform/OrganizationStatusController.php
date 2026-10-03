<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Platform\ChangeOrganizationStatus;
use App\Domain\Platform\OrganizationStatus;
use App\Domain\Platform\PlatformAbility;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\AuthorizesPlatform;
use App\Http\Requests\Platform\ChangeOrganizationStatusRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;

/** Lifecycle changes (suspend, archive, cancel, reactivate): all rules live in ChangeOrganizationStatus. */
final class OrganizationStatusController extends Controller
{
    use AuthorizesPlatform;

    public function __invoke(ChangeOrganizationStatusRequest $request, Organization $organization, ChangeOrganizationStatus $change): RedirectResponse
    {
        $this->allow(PlatformAbility::ChangeOrganizationStatus);

        $to = OrganizationStatus::from($request->validated('status'));
        $organization = $change($organization, $to, $request->user(), $request->validated('reason'));

        return redirect()->route('platform.organizations.show', $organization)
            ->with('success', "{$organization->name} is now {$organization->status->label()}.");
    }
}
